<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'] ?? '';
if (!has_system_permission('inventory.access')) {
    http_response_code(403);
    exit('Inventory workspace permission is required.');
}

$connection = db();
ensure_inventory_tables();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

/**
 * Stock condition derived from quantity on hand versus the reorder level.
 */
function stock_state(int $onHand, int $reorderLevel, bool $active): string {
    if (!$active) {
        return 'Discontinued';
    }
    if ($onHand <= 0) {
        return 'Out of Stock';
    }
    if ($onHand <= $reorderLevel) {
        return 'Low Stock';
    }
    return 'In Stock';
}

const MOVEMENT_TYPES = ['Opening', 'Receipt', 'Issue', 'Return', 'Adjustment', 'Loss'];

/* ── POST actions ─────────────────────────────────────────────────────── */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'save_item') {
            $id          = (int) ($_POST['id'] ?? 0);
            $itemName    = trim($_POST['item_name'] ?? '');
            $itemCode    = strtoupper(trim($_POST['item_code'] ?? ''));
            $categoryId  = (int) ($_POST['category_id'] ?? 0) ?: null;
            $supplierId  = (int) ($_POST['supplier_id'] ?? 0) ?: null;
            $unit        = trim($_POST['unit'] ?? '') ?: 'Unit';
            $reorder     = (int) ($_POST['reorder_level'] ?? 0);
            $unitCost    = filter_var($_POST['unit_cost'] ?? '', FILTER_VALIDATE_FLOAT);
            $location    = trim($_POST['location'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');
            $openingQty  = (int) ($_POST['opening_quantity'] ?? 0);
            $isActive    = $id > 0 ? (isset($_POST['is_active']) ? 1 : 0) : 1;

            if ($itemName === '' || $unitCost === false || $unitCost < 0 || $reorder < 0) {
                throw new InvalidArgumentException('Enter an item name, a valid unit cost, and a reorder level of zero or more.');
            }
            if ($openingQty < 0) {
                throw new InvalidArgumentException('Opening quantity cannot be negative.');
            }
            if ($itemCode === '') {
                $itemCode = 'INV-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $itemName), 0, 6));
            }
            if ($id > 0 && $openingQty !== 0) {
                throw new InvalidArgumentException('Use Record stock movement to change stock on an existing item.');
            }

            if ($id > 0) {
                $stmt = $connection->prepare(
                    'UPDATE inventory_items
                        SET item_code = ?, item_name = ?, category_id = ?, supplier_id = ?, unit = ?,
                            reorder_level = ?, unit_cost = ?, location = ?, notes = ?, is_active = ?
                      WHERE id = ?'
                );
                $stmt->execute([$itemCode, $itemName, $categoryId, $supplierId, $unit, $reorder, $unitCost, $location ?: null, $notes ?: null, $isActive, $id]);
                $message = 'Inventory item updated successfully.';
            } else {
                $stmt = $connection->prepare(
                    'INSERT INTO inventory_items
                        (item_code, item_name, category_id, supplier_id, unit, quantity_on_hand,
                         reorder_level, unit_cost, location, is_active, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$itemCode, $itemName, $categoryId, $supplierId, $unit, $openingQty, $reorder, $unitCost, $location ?: null, $isActive, $notes ?: null]);
                $id = (int) $connection->lastInsertId();

                if ($openingQty > 0) {
                    $movement = $connection->prepare(
                        'INSERT INTO inventory_movements
                            (item_id, movement_type, quantity, balance_after, unit_cost, movement_date, notes, recorded_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $movement->execute([
                        $id,
                        'Opening',
                        $openingQty,
                        $openingQty,
                        $unitCost,
                        date('Y-m-d'),
                        'Opening balance recorded when the item was created.',
                        (int) $user['id']
                    ]);
                }
                $message = 'Inventory item added successfully.';
            }

        } elseif ($action === 'record_movement') {
            $itemId        = (int) ($_POST['item_id'] ?? 0);
            $movementType  = $_POST['movement_type'] ?? '';
            $quantity      = (int) ($_POST['quantity'] ?? 0);
            $unitCost      = filter_var($_POST['unit_cost'] ?? '', FILTER_VALIDATE_FLOAT);
            $reference     = trim($_POST['reference'] ?? '');
            $supplierId    = (int) ($_POST['supplier_id'] ?? 0) ?: null;
            $movementDate  = $_POST['movement_date'] ?? '';
            $notes         = trim($_POST['notes'] ?? '');

            if (!in_array($movementType, MOVEMENT_TYPES, true)) {
                throw new InvalidArgumentException('Select a valid movement type.');
            }
            if ($quantity < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }
            if (!DateTime::createFromFormat('Y-m-d', $movementDate)) {
                throw new InvalidArgumentException('Enter a valid movement date.');
            }

            $connection->beginTransaction();
            try {
                $lock = $connection->prepare('SELECT item_name, quantity_on_hand, unit_cost FROM inventory_items WHERE id = ? FOR UPDATE');
                $lock->execute([$itemId]);
                $item = $lock->fetch();
                if (!$item) {
                    throw new InvalidArgumentException('That inventory item no longer exists.');
                }

                // Receipts and Returns add stock; every other type removes it.
                $delta = in_array($movementType, ['Receipt', 'Return'], true) ? $quantity : -$quantity;
                $balance = (int) $item['quantity_on_hand'] + $delta;
                if ($balance < 0) {
                    throw new RuntimeException('This issue exceeds the stock on hand (' . (int) $item['quantity_on_hand'] . ').');
                }

                $connection->prepare('UPDATE inventory_items SET quantity_on_hand = ? WHERE id = ?')
                    ->execute([$balance, $itemId]);

                $connection->prepare(
                    'INSERT INTO inventory_movements
                        (item_id, movement_type, quantity, balance_after, unit_cost, reference, supplier_id, movement_date, notes, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $itemId,
                    $movementType,
                    $delta,
                    $balance,
                    $unitCost === false ? (float) $item['unit_cost'] : $unitCost,
                    $reference ?: null,
                    $supplierId,
                    $movementDate,
                    $notes ?: null,
                    (int) $user['id']
                ]);

                $connection->commit();
                $message = 'Stock movement recorded for ' . $item['item_name'] . '.';
            } catch (Throwable $inner) {
                $connection->rollBack();
                throw $inner;
            }

        } elseif ($action === 'save_supplier') {
            $supplierName = trim($_POST['supplier_name'] ?? '');
            $contact      = trim($_POST['contact_person'] ?? '');
            $phone        = trim($_POST['phone'] ?? '');
            $email        = trim($_POST['email'] ?? '');
            $address      = trim($_POST['address'] ?? '');

            if ($supplierName === '') {
                throw new InvalidArgumentException('Enter a supplier name.');
            }

            $connection->prepare(
                'INSERT INTO inventory_suppliers (supplier_name, contact_person, phone, email, address)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE contact_person = VALUES(contact_person), phone = VALUES(phone),
                                         email = VALUES(email), address = VALUES(address)'
            )->execute([$supplierName, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null]);

            $message = 'Supplier record saved successfully.';

        } elseif ($action === 'toggle_item') {
            $stmt = $connection->prepare('UPDATE inventory_items SET is_active = 1 - is_active WHERE id = ?');
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $message = 'Item status updated.';

        } elseif ($action === 'delete_item') {
            $stmt = $connection->prepare('DELETE FROM inventory_items WHERE id = ?');
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $message = 'Item and its movement history deleted.';
        }
    }

    if (isset($_GET['edit'])) {
        $stmt = $connection->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $stmt->execute([(int) $_GET['edit']]);
        $editItem = $stmt->fetch() ?: null;
    } else {
        $editItem = null;
    }
} catch (Throwable $exception) {
    $editItem = $editItem ?? null;
    $error = $exception instanceof PDOException && $exception->getCode() === '23000'
        ? 'That item code or supplier name already exists.'
        : $exception->getMessage();
}

/* ── Filters ──────────────────────────────────────────────────────────── */
$search   = trim($_GET['q'] ?? '');
$category = (int) ($_GET['category_id'] ?? 0);
$stock    = $_GET['stock'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(i.item_name LIKE ? OR i.item_code LIKE ? OR i.location LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
if ($category > 0) {
    $where[] = 'i.category_id = ?';
    $params[] = $category;
}
if (in_array($stock, ['In Stock', 'Low Stock', 'Out of Stock', 'Discontinued'], true)) {
    if ($stock === 'In Stock') {
        $where[] = 'i.is_active = 1 AND i.quantity_on_hand > i.reorder_level';
    } elseif ($stock === 'Low Stock') {
        $where[] = 'i.is_active = 1 AND i.quantity_on_hand > 0 AND i.quantity_on_hand <= i.reorder_level';
    } elseif ($stock === 'Out of Stock') {
        $where[] = 'i.is_active = 1 AND i.quantity_on_hand <= 0';
    } else {
        $where[] = 'i.is_active = 0';
    }
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$items = $connection->prepare(
    "SELECT i.*, c.category_name, s.supplier_name
     FROM inventory_items i
     LEFT JOIN inventory_categories c ON c.id = i.category_id
     LEFT JOIN inventory_suppliers  s ON s.id = i.supplier_id
     $whereSql
     ORDER BY i.item_name"
);
$items->execute($params);
$items = $items->fetchAll();

/* ── Dashboard figures ────────────────────────────────────────────────── */
$summary = $connection->query(
    "SELECT COUNT(*) AS item_count,
            COALESCE(SUM(quantity_on_hand * unit_cost), 0) AS stock_value,
            COALESCE(SUM(quantity_on_hand), 0) AS units_in_stock
     FROM inventory_items WHERE is_active = 1"
)->fetch();

$lowStockCount = (int) $connection->query(
    'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity_on_hand <= reorder_level'
)->fetchColumn();
$outOfStockCount = (int) $connection->query(
    'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND quantity_on_hand <= 0'
)->fetchColumn();
$todayMovements = (int) $connection->query(
    'SELECT COUNT(*) FROM inventory_movements WHERE movement_date = CURRENT_DATE'
)->fetchColumn();
$categoryCount = (int) $connection->query('SELECT COUNT(*) FROM inventory_categories')->fetchColumn();
$supplierCount = (int) $connection->query('SELECT COUNT(*) FROM inventory_suppliers')->fetchColumn();

$lowStockItems = $connection->query(
    'SELECT id, item_code, item_name, quantity_on_hand, reorder_level, unit, unit_cost
     FROM inventory_items
     WHERE is_active = 1 AND quantity_on_hand <= reorder_level
     ORDER BY quantity_on_hand ASC, item_name LIMIT 8'
)->fetchAll();

$recentMovements = $connection->query(
    'SELECT m.id, m.movement_type, m.quantity, m.balance_after, m.movement_date, m.reference,
            i.item_code, i.item_name, i.unit,
            s.supplier_name
     FROM inventory_movements m
     JOIN inventory_items i ON i.id = m.item_id
     LEFT JOIN inventory_suppliers s ON s.id = m.supplier_id
     ORDER BY m.movement_date DESC, m.id DESC LIMIT 12'
)->fetchAll();

$categorySummary = $connection->query(
    "SELECT c.category_name, COUNT(i.id) AS item_count,
            COALESCE(SUM(i.quantity_on_hand * i.unit_cost), 0) AS value
     FROM inventory_categories c
     LEFT JOIN inventory_items i ON i.category_id = c.id AND i.is_active = 1
     GROUP BY c.id, c.category_name
     ORDER BY value DESC, c.category_name"
)->fetchAll();

$maxCategoryValue = 0;
foreach ($categorySummary as $row) {
    $maxCategoryValue = max($maxCategoryValue, (float) $row['value']);
}

$movementsToday = $connection->query(
    "SELECT movement_type, COUNT(*) AS entries, COALESCE(SUM(ABS(quantity)), 0) AS units
     FROM inventory_movements WHERE movement_date = CURRENT_DATE
     GROUP BY movement_type"
)->fetchAll();

$categories = $connection->query('SELECT id, category_name FROM inventory_categories ORDER BY category_name')->fetchAll();
$suppliers  = $connection->query('SELECT id, supplier_name FROM inventory_suppliers ORDER BY supplier_name')->fetchAll();
$supplierRows = $connection->query(
    'SELECT s.*, COUNT(i.id) AS item_count,
            COALESCE(SUM(i.quantity_on_hand * i.unit_cost), 0) AS supplied_value
     FROM inventory_suppliers s
     LEFT JOIN inventory_items i ON i.supplier_id = s.id
     GROUP BY s.id, s.supplier_name, s.contact_person, s.phone, s.email, s.address
     ORDER BY s.supplier_name'
)->fetchAll();

$firstName = trim((string) $user['full_name']);
$firstName = explode(' ', $firstName)[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory | Mandela International French School IMS</title>
    <style>
        :root {
            --navy: #123b6d;
            --navy-dark: #0b2d55;
            --ink: #172b4d;
            --muted: #718096;
            --line: #e7edf4;
            --blue: #1763a6;
            --teal: #0d9488;
            --violet: #6d28d9;
            --amber: #d97706;
            --rose: #be123c;
            --emerald: #15803d;
            --radius: 12px;
            --shadow: 0 4px 18px rgba(15, 40, 70, .07);
            --shadow-hi: 0 14px 34px rgba(15, 40, 70, .13);
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); font: 14px/1.55 Arial, Helvetica, sans-serif; background: #f6f8fc; background-image: radial-gradient(1100px 420px at 6% -8%, #dbeafe 0, rgba(219,234,254,0) 62%), radial-gradient(900px 400px at 100% 0, #ede9fe 0, rgba(237,233,254,0) 58%); background-attachment: fixed; }
        a { color: inherit; text-decoration: none; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 245px; flex-shrink: 0; padding: 26px 18px; color: #dce8f7; background: linear-gradient(180deg, #0b2d55, #092540); }
        .brand { display: flex; align-items: center; gap: 11px; padding: 0 10px 30px; color: #fff; }
        .brand-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; color: var(--navy-dark); background: #f6c453; font-weight: 700; }
        .brand strong { display: block; font-size: 14px; }
        .brand small { display: block; margin-top: 4px; color: #9fb6d3; font-size: 10px; }
        .nav-label { padding: 0 11px 9px; color: #7f9abb; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .nav a { display: block; margin: 4px 0; padding: 12px 11px; border-radius: 7px; color: #c8d8eb; }
        .nav a.active, .nav a:hover { color: #fff; background: #1d4c82; }
        .nav-icon { display: inline-block; width: 25px; color: #9fbbe0; font-weight: 700; }
        .sidebar-footer { margin-top: 40px; padding: 17px 11px; border-top: 1px solid #28517f; color: #9fb6d3; font-size: 12px; line-height: 1.5; }
        .content { flex: 1; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; min-height: 74px; padding: 0 4%; border-bottom: 1px solid var(--line); background: #fff; }
        .topbar p { margin: 4px 0 0; color: var(--muted); font-size: 12px; }
        .profile { display: flex; align-items: center; gap: 10px; }
        .avatar { display: grid; width: 36px; height: 36px; place-items: center; border-radius: 50%; color: #fff; background: linear-gradient(135deg, var(--blue), var(--teal)); font-weight: 700; }
        .profile strong { display: block; font-size: 13px; }
        .profile a { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; }
        main { max-width: 1360px; margin: 0 auto; padding: 30px 4% 46px; }
        h1 { margin: 0; font-size: 27px; }
        h2 { margin: 0; font-size: 17px; }
        .welcome { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
        .welcome p { margin: 8px 0 0; color: var(--muted); }
        .date { padding: 10px 14px; border: 1px solid var(--line); border-radius: 999px; color: #64748b; background: linear-gradient(135deg, #eaf3fb, #e0f2f1); font-size: 12px; font-weight: 700; }

        .stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 24px; }
        .stat { position: relative; padding: 18px; overflow: hidden; border: 1px solid var(--line); border-radius: var(--radius); background: #fff; box-shadow: var(--shadow); transition: transform .2s, box-shadow .2s; }
        .stat::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--blue), #38bdf8); }
        .stat:nth-child(2)::before { background: linear-gradient(90deg, var(--emerald), #4ade80); }
        .stat:nth-child(3)::before { background: linear-gradient(90deg, var(--teal), #5eead4); }
        .stat:nth-child(4)::before { background: linear-gradient(90deg, var(--amber), #fbbf24); }
        .stat:nth-child(5)::before { background: linear-gradient(90deg, var(--rose), #fb7185); }
        .stat:nth-child(6)::before { background: linear-gradient(90deg, var(--violet), #c4b5fd); }
        .stat::after { content: ""; position: absolute; right: -26px; top: -26px; width: 92px; height: 92px; border-radius: 50%; background: #f2f7fc; }
        .stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-hi); }
        .stat-label { position: relative; z-index: 1; color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; }
        .stat-value { position: relative; z-index: 1; margin-top: 9px; font-size: 25px; font-weight: 700; color: #0f3557; }
        .stat-note { position: relative; z-index: 1; margin-top: 6px; color: var(--muted); font-size: 11px; }

        .panel { min-width: 0; margin-bottom: 20px; padding: 20px 22px; border: 1px solid var(--line); border-radius: var(--radius); background: #fff; box-shadow: var(--shadow); }
        .panel h2::after { content: ""; display: block; width: 44px; height: 3px; margin-top: 7px; border-radius: 2px; background: linear-gradient(90deg, var(--blue), var(--teal)); }
        .section-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 15px; }
        .section-title span { color: var(--muted); font-size: 12px; }
        .panels { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr); gap: 20px; }

        table { width: 100%; border-collapse: collapse; }
        th { padding: 11px 9px; color: #5c7290; background: linear-gradient(180deg, #f2f7fc, #eaf1f9); font-size: 10px; letter-spacing: .6px; text-align: left; text-transform: uppercase; }
        th:first-child { border-top-left-radius: 9px; }
        th:last-child { border-top-right-radius: 9px; }
        td { padding: 11px 9px; border-top: 1px solid #edf1f5; color: #42526e; font-size: 12px; }
        td strong { display: block; color: var(--ink); font-size: 13px; }
        tbody tr { transition: background .15s; }
        tbody tr:hover { background: #f4f9ff; }
        .table-wrap { overflow: auto; border-radius: 9px; }
        .money { color: var(--blue); font-weight: 700; text-align: right; }
        .empty { padding: 18px; border: 1px dashed #dbe4ef; border-radius: 9px; color: var(--muted); background: #fafcff; text-align: center; }

        .pill { display: inline-block; padding: 4px 11px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: .3px; white-space: nowrap; }
        .st-In-Stock { color: #17603f; background: #dcfce7; }
        .st-Low-Stock { color: #8a5200; background: #fff3d6; }
        .st-Out-of-Stock { color: #9f1239; background: #ffe4e6; }
        .st-Discontinued { color: #52637a; background: #eef1f6; }
        .mv-Receipt, .mv-Return, .mv-Opening { color: #17603f; }
        .mv-Issue, .mv-Loss { color: #9f1239; }
        .mv-Adjustment { color: #8a5200; }

        .bar { position: relative; height: 7px; width: 110px; border-radius: 4px; background: #edf1f5; overflow: hidden; }
        .bar span { display: block; height: 100%; border-radius: 4px; background: linear-gradient(90deg, var(--blue), var(--teal)); }
        .bar.low span { background: linear-gradient(90deg, var(--amber), #fbbf24); }
        .bar.out span { background: linear-gradient(90deg, var(--rose), #fb7185); }
        .qty { display: flex; align-items: center; gap: 9px; }

        .notice { padding: 13px 16px; margin-bottom: 18px; border: 1px solid #bfe7d6; border-left: 5px solid var(--emerald); border-radius: 9px; color: #1d6b4c; background: #eafaf3; box-shadow: 0 4px 14px rgba(21,128,61,.09); }
        .error { border-color: #f3c9c9; border-left-color: var(--rose); color: #a33a3a; background: #fff1f1; }

        form.filters { display: grid; grid-template-columns: 2fr 1.3fr 1.3fr auto auto; gap: 12px; align-items: end; }
        .field { display: block; }
        label { display: block; margin: 0 0 5px; color: #42607d; font-size: 11px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #d8e0ea; border-radius: 8px; background: #fbfdff; font: inherit; transition: border-color .18s, box-shadow .18s, background .18s; }
        input:hover, select:hover, textarea:hover { border-color: #b6c9de; }
        input:focus, select:focus, textarea:focus { outline: 0; border-color: var(--blue); background: #fff; box-shadow: 0 0 0 4px rgba(23,99,166,.14); }
        input[readonly] { color: #64748b; background: #f1f5f9; }
        textarea { min-height: 62px; resize: vertical; }
        button, .btn { display: inline-block; padding: 10px 17px; border: 0; border-radius: 8px; color: #fff; background: linear-gradient(135deg, var(--blue), var(--teal)); box-shadow: 0 6px 16px rgba(23,99,166,.26); cursor: pointer; font: inherit; font-weight: 700; white-space: nowrap; transition: transform .18s, box-shadow .18s, filter .18s; }
        button:hover, .btn:hover { transform: translateY(-2px); filter: saturate(1.12); box-shadow: 0 10px 22px rgba(23,99,166,.32); }
        button.ghost { color: var(--blue); background: #fff; border: 1px solid #cfe2f6; box-shadow: none; }
        button.ghost:hover { background: #eaf3fb; }
        button.danger { background: linear-gradient(135deg, #be123c, #e11d48); box-shadow: 0 6px 16px rgba(190,18,60,.26); }
        button.link { padding: 0; color: var(--blue); background: none; box-shadow: none; font-size: 12px; }
        button.link:hover { color: var(--teal); box-shadow: none; text-decoration: underline; transform: none; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
        .form-grid .span-2 { grid-column: span 2; }
        .form-actions { display: flex; gap: 10px; align-items: center; margin-top: 16px; }
        .hint { margin: 6px 0 0; color: var(--muted); font-size: 11px; }

        .alert-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 13px; margin-bottom: 9px; border: 1px solid #f5dda2; border-left: 5px solid var(--amber); border-radius: 9px; background: #fffaf0; }
        .alert-row.critical { border-color: #f3c9c9; border-left-color: var(--rose); background: #fff5f5; }
        .alert-row strong { display: block; color: #5b3a05; font-size: 13px; }
        .alert-row.critical strong { color: #7f1d1d; }
        .alert-row small { color: #8a5200; }
        .alert-row.critical small { color: #9f1239; }

        @media (max-width: 1250px) { .stats { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 1150px) { .sidebar { width: 205px; } .panels { grid-template-columns: 1fr; } form.filters { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 760px) { .layout { display: block; } .sidebar { width: auto; padding: 16px; } .brand { padding-bottom: 16px; } .nav { display: flex; gap: 6px; overflow: auto; } .nav-label, .sidebar-footer { display: none; } .nav a { white-space: nowrap; } }
        @media (max-width: 560px) { .stats, .form-grid { grid-template-columns: 1fr; } .form-grid .span-2 { grid-column: auto; } .welcome { flex-direction: column; align-items: flex-start; } .topbar { flex-direction: column; align-items: flex-start; gap: 12px; padding: 16px 5%; } main { padding: 24px 5%; } }
        @media (hover: none) { .stat:hover, button:hover, .btn:hover { transform: none; } }
        @media print { body { background: #fff; } .sidebar, form, button, .btn { display: none; } .panel, .stat { box-shadow: none; } }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">MF</div>
            <div><strong>Mandela French</strong><small>Information Management System</small></div>
        </div>
        <div class="nav-label">Inventory</div>
        <nav class="nav">
            <a class="active" href="inventory.php"><span class="nav-icon">01</span>Inventory Dashboard</a>
            <a href="inventory.php#items"><span class="nav-icon">02</span>Stock Records</a>
            <a href="inventory.php#movements"><span class="nav-icon">03</span>Stock Movements</a>
            <a href="inventory.php#alerts"><span class="nav-icon">04</span>Reorder Alerts</a>
            <a href="inventory.php#suppliers"><span class="nav-icon">05</span>Suppliers</a>
            <a href="finance.php"><span class="nav-icon">06</span>Finance Workspace</a>
            <a href="dashboard.php"><span class="nav-icon">07</span>Main Dashboard</a>
        </nav>
        <div class="sidebar-footer">Logged in as<br><strong><?= e($role) ?></strong></div>
    </aside>

    <div class="content">
        <header class="topbar">
            <div><strong>Inventory and Stores</strong><p>Track school assets, stock levels, suppliers and every movement.</p></div>
            <div class="profile">
                <div class="avatar"><?= e(strtoupper(substr($firstName, 0, 1))) ?></div>
                <div><strong><?= e($user['full_name']) ?></strong><a href="logout.php">Sign out</a></div>
            </div>
        </header>

        <main>
            <section class="welcome">
                <div>
                    <h1>Inventory dashboard</h1>
                    <p>Every stock record, valuation and movement for the school store.</p>
                </div>
                <div class="date"><?= e(date('l, F j, Y')) ?></div>
            </section>

            <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

            <section class="stats">
                <div class="stat"><div class="stat-label">Active items</div><div class="stat-value"><?= e((string) $summary['item_count']) ?></div><div class="stat-note">Across <?= e((string) $categoryCount) ?> categories</div></div>
                <div class="stat"><div class="stat-label">Stock value</div><div class="stat-value"><?= e(number_format((float) $summary['stock_value'], 0)) ?></div><div class="stat-note">Quantity &times; unit cost</div></div>
                <div class="stat"><div class="stat-label">Units in stock</div><div class="stat-value"><?= e(number_format((float) $summary['units_in_stock'], 0)) ?></div><div class="stat-note">Total on-hand quantity</div></div>
                <div class="stat"><div class="stat-label">Needs reorder</div><div class="stat-value"><?= e((string) $lowStockCount) ?></div><div class="stat-note">At or below reorder level</div></div>
                <div class="stat"><div class="stat-label">Out of stock</div><div class="stat-value"><?= e((string) $outOfStockCount) ?></div><div class="stat-note">Nothing on hand</div></div>
                <div class="stat"><div class="stat-label">Today's movements</div><div class="stat-value"><?= e((string) $todayMovements) ?></div><div class="stat-note"><?= e((string) $supplierCount) ?> suppliers on file</div></div>
            </section>

            <div class="panels">
                <section class="panel">
                    <div class="section-title"><h2>Record a stock movement</h2><span>Receipts, issues, returns, losses</span></div>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="record_movement">
                        <div class="form-grid">
                            <div class="span-2"><label>Inventory item</label>
                                <select name="item_id" required>
                                    <option value="">Choose an item</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= e((string) $item['id']) ?>"><?= e($item['item_code'] . ' — ' . $item['item_name'] . ' (' . $item['quantity_on_hand'] . ' ' . $item['unit'] . ')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label>Movement type</label>
                                <select name="movement_type" required>
                                    <?php foreach (MOVEMENT_TYPES as $type): ?><option><?= e($type) ?></option><?php endforeach; ?>
                                </select>
                                <p class="hint">Receipt and Return add stock; all others remove it.</p>
                            </div>
                            <div><label>Quantity</label><input type="number" name="quantity" min="1" step="1" value="1" required></div>
                            <div><label>Unit cost</label><input type="number" name="unit_cost" min="0" step="0.01" placeholder="Leave blank to keep current cost"></div>
                            <div><label>Movement date</label><input type="date" name="movement_date" value="<?= e(date('Y-m-d')) ?>" required></div>
                            <div><label>Reference</label><input name="reference" placeholder="Invoice, GRN or document no."></div>
                            <div><label>Supplier</label>
                                <select name="supplier_id">
                                    <option value="">Not applicable</option>
                                    <?php foreach ($suppliers as $supplier): ?><option value="<?= e((string) $supplier['id']) ?>"><?= e($supplier['supplier_name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="span-2"><label>Notes</label><textarea name="notes" placeholder="Reason, department, or receiving details"></textarea></div>
                        </div>
                        <div class="form-actions"><button type="submit">Save movement</button></div>
                    </form>
                </section>

                <section class="panel" id="alerts">
                    <div class="section-title"><h2>Reorder alerts</h2><span><?= e((string) $lowStockCount) ?> item(s) need attention</span></div>
                    <?php if ($lowStockItems): ?>
                        <?php foreach ($lowStockItems as $low): ?>
                            <div class="alert-row<?= (int) $low['quantity_on_hand'] <= 0 ? ' critical' : '' ?>">
                                <div>
                                    <strong><?= e($low['item_name']) ?></strong>
                                    <small><?= e($low['item_code']) ?> &middot; <?= e((int) $low['quantity_on_hand']) ?> / <?= e((int) $low['reorder_level']) ?> <?= e($low['unit']) ?> &middot; <?= e(number_format((float) $low['unit_cost'], 0)) ?> each</small>
                                </div>
                                <span class="pill <?= (int) $low['quantity_on_hand'] <= 0 ? 'st-Out-of-Stock' : 'st-Low-Stock' ?>">
                                    <?= (int) $low['quantity_on_hand'] <= 0 ? 'Out of stock' : 'Low stock' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">Every active item is above its reorder level.</div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="panels">
                <section class="panel">
                    <div class="section-title"><h2>Stock value by category</h2><span>Share of total inventory value</span></div>
                    <?php if ($categorySummary): ?>
                        <table>
                            <thead><tr><th>Category</th><th>Items</th><th style="width:36%">Value</th><th style="text-align:right">Amount</th></tr></thead>
                            <tbody>
                            <?php foreach ($categorySummary as $row): $share = $maxCategoryValue > 0 ? ((float) $row['value'] / $maxCategoryValue) * 100 : 0; ?>
                                <tr>
                                    <td><strong><?= e($row['category_name']) ?></strong></td>
                                    <td><?= e((string) $row['item_count']) ?></td>
                                    <td><div class="bar"><span style="width:<?= e(number_format($share, 1)) ?>%"></span></div></td>
                                    <td class="money"><?= e(number_format((float) $row['value'], 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty">No categories available.</div><?php endif; ?>
                </section>

                <section class="panel">
                    <div class="section-title"><h2>Today's movement summary</h2><span>Entries recorded today</span></div>
                    <?php if ($movementsToday): ?>
                        <table>
                            <thead><tr><th>Movement</th><th>Entries</th><th style="text-align:right">Units</th></tr></thead>
                            <tbody>
                            <?php foreach ($movementsToday as $row): ?>
                                <tr>
                                    <td class="mv-<?= e($row['movement_type']) ?>"><strong style="color:inherit"><?= e($row['movement_type']) ?></strong></td>
                                    <td><?= e((string) $row['entries']) ?></td>
                                    <td class="money"><?= e(number_format((float) $row['units'], 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty">No stock movements recorded today.</div><?php endif; ?>
                </section>
            </div>

            <section class="panel" id="items">
                <div class="section-title"><h2>Stock records</h2><span><?= e((string) count($items)) ?> item(s) matching the filter</span></div>

                <form method="get" class="filters">
                    <div class="field"><label>Search</label><input name="q" value="<?= e($search) ?>" placeholder="Item name, code or location"></div>
                    <div class="field"><label>Category</label>
                        <select name="category_id">
                            <option value="0">All categories</option>
                            <?php foreach ($categories as $cat): ?><option value="<?= e((string) $cat['id']) ?>"<?= $category === (int) $cat['id'] ? ' selected' : '' ?>><?= e($cat['category_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>Stock condition</label>
                        <select name="stock">
                            <option value="">All conditions</option>
                            <?php foreach (['In Stock', 'Low Stock', 'Out of Stock', 'Discontinued'] as $state): ?><option<?= $stock === $state ? ' selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Filter</button>
                    <a class="btn ghost" href="inventory.php">Reset</a>
                </form>

                <?php if ($items): ?>
                    <div class="table-wrap" style="margin-top:16px">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th><th>Category</th><th>Supplier</th><th>On hand</th>
                                    <th style="text-align:right">Unit cost</th><th style="text-align:right">Value</th>
                                    <th>Condition</th><th>Location</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item):
                                $onHand  = (int) $item['quantity_on_hand'];
                                $reorder = (int) $item['reorder_level'];
                                $state   = stock_state($onHand, $reorder, (int) $item['is_active'] === 1);
                                $pct     = $onHand > 0 && $reorder > 0 ? min(100, ($onHand / max($reorder * 3, 1)) * 100) : ($onHand > 0 ? 100 : 0);
                                $barClass = $state === 'Out of Stock' ? 'out' : ($state === 'Low Stock' ? 'low' : '');
                            ?>
                                <tr>
                                    <td><strong><?= e($item['item_name']) ?></strong><?= e($item['item_code']) ?> &middot; <?= e($item['unit']) ?></td>
                                    <td><?= e($item['category_name'] ?: 'Uncategorised') ?></td>
                                    <td><?= e($item['supplier_name'] ?: 'Not assigned') ?></td>
                                    <td>
                                        <div class="qty">
                                            <div class="bar <?= e($barClass) ?>"><span style="width:<?= e(number_format($pct, 1)) ?>%"></span></div>
                                            <strong style="display:inline"><?= e((string) $onHand) ?></strong>
                                        </div>
                                        <small style="color:#8290a3">Reorder at <?= e((string) $reorder) ?></small>
                                    </td>
                                    <td class="money"><?= e(number_format((float) $item['unit_cost'], 2)) ?></td>
                                    <td class="money"><?= e(number_format($onHand * (float) $item['unit_cost'], 2)) ?></td>
                                    <td><span class="pill st-<?= e($state) ?>"><?= e($state) ?></span></td>
                                    <td><?= e($item['location'] ?: '—') ?></td>
                                    <td style="white-space:nowrap">
                                        <a class="btn ghost" href="inventory.php?edit=<?= e((string) $item['id']) ?>#items">Edit</a>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle_item">
                                            <input type="hidden" name="id" value="<?= e((string) $item['id']) ?>">
                                            <button type="submit" class="link"><?= (int) $item['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                                        </form>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= e(addslashes($item['item_name'])) ?> and its movement history?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="id" value="<?= e((string) $item['id']) ?>">
                                            <button type="submit" class="link" style="color:#be123c">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?><div class="empty">No inventory items match this filter. Add the first item below.</div><?php endif; ?>
            </section>

            <div class="panels">
                <section class="panel">
                    <div class="section-title"><h2><?= $editItem ? 'Edit inventory item' : 'Add an inventory item' ?></h2><span><?= $editItem ? 'Editing ' . e($editItem['item_code']) : 'New stock record' ?></span></div>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="save_item">
                        <?php if ($editItem): ?><input type="hidden" name="id" value="<?= e((string) $editItem['id']) ?>"><?php endif; ?>
                        <div class="form-grid">
                            <div><label>Item name</label><input name="item_name" value="<?= e($editItem['item_name'] ?? '') ?>" placeholder="e.g. A4 ruled exercise book" required></div>
                            <div><label>Item code</label><input name="item_code" value="<?= e($editItem['item_code'] ?? '') ?>" placeholder="Auto-generated if blank"></div>
                            <div><label>Category</label>
                                <select name="category_id">
                                    <option value="0">Uncategorised</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= e((string) $cat['id']) ?>"<?= (int) ($editItem['category_id'] ?? 0) === (int) $cat['id'] ? ' selected' : '' ?>><?= e($cat['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label>Supplier</label>
                                <select name="supplier_id">
                                    <option value="0">Not assigned</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= e((string) $supplier['id']) ?>"<?= (int) ($editItem['supplier_id'] ?? 0) === (int) $supplier['id'] ? ' selected' : '' ?>><?= e($supplier['supplier_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label>Unit of measure</label><input name="unit" value="<?= e($editItem['unit'] ?? 'Unit') ?>" placeholder="Unit, Box, Packet, Litre" required></div>
                            <div><label>Storage location</label><input name="location" value="<?= e($editItem['location'] ?? '') ?>" placeholder="e.g. Store room A, shelf 3"></div>
                            <div><label>Unit cost</label><input type="number" name="unit_cost" min="0" step="0.01" value="<?= e($editItem['unit_cost'] ?? '0') ?>" required></div>
                            <div><label>Reorder level</label><input type="number" name="reorder_level" min="0" step="1" value="<?= e((string) ($editItem['reorder_level'] ?? 0)) ?>" required></div>
                            <?php if ($editItem): ?>
                                <div class="span-2"><label>Current quantity on hand</label><input value="<?= e((string) $editItem['quantity_on_hand']) ?>" readonly></div>
                            <?php else: ?>
                                <div class="span-2"><label>Opening quantity</label><input type="number" name="opening_quantity" min="0" step="1" value="0"><p class="hint">Recorded as an Opening movement. Later changes go through Record a stock movement.</p></div>
                            <?php endif; ?>
                            <div class="span-2"><label>Notes</label><textarea name="notes" placeholder="Specification, brand, or handling notes"><?= e($editItem['notes'] ?? '') ?></textarea></div>
                            <div class="span-2"><label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:13px"><input type="checkbox" name="is_active" style="width:16px;height:16px"<?= !isset($editItem['is_active']) || (int) $editItem['is_active'] === 1 ? ' checked' : '' ?>> Item is active and available for issue</label></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit"><?= $editItem ? 'Save changes' : 'Add item' ?></button>
                            <?php if ($editItem): ?><a class="btn ghost" href="inventory.php#items">Cancel</a><?php endif; ?>
                        </div>
                    </form>
                </section>

                <section class="panel" id="movements">
                    <div class="section-title"><h2>Recent stock movements</h2><span>Latest 12 entries</span></div>
                    <?php if ($recentMovements): ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Date</th><th>Item</th><th>Movement</th><th style="text-align:right">Change</th><th style="text-align:right">Balance</th><th>Reference</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentMovements as $movement): ?>
                                    <tr>
                                        <td><?= e($movement['movement_date']) ?></td>
                                        <td><strong><?= e($movement['item_name']) ?></strong><?= e($movement['item_code']) ?></td>
                                        <td class="mv-<?= e($movement['movement_type']) ?>"><strong style="color:inherit"><?= e($movement['movement_type']) ?></strong><?= $movement['supplier_name'] ? '<br><small>' . e($movement['supplier_name']) . '</small>' : '' ?></td>
                                        <td class="money" style="color:<?= (int) $movement['quantity'] >= 0 ? '#15803d' : '#be123c' ?>"><?= (int) $movement['quantity'] >= 0 ? '+' : '' ?><?= e((string) $movement['quantity']) ?></td>
                                        <td class="money"><?= e((string) $movement['balance_after']) ?> <?= e($movement['unit']) ?></td>
                                        <td><?= e($movement['reference'] ?: '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?><div class="empty">No stock movements recorded yet.</div><?php endif; ?>
                </section>
            </div>

            <section class="panel" id="suppliers">
                <div class="section-title"><h2>Suppliers</h2><span><?= e((string) $supplierCount) ?> supplier(s) on file</span></div>
                <div class="panels" style="margin-bottom:0">
                    <div>
                        <?php if ($supplierRows): ?>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Supplier</th><th>Contact</th><th>Items</th><th style="text-align:right">Stock value</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($supplierRows as $supplier): ?>
                                        <tr>
                                            <td><strong><?= e($supplier['supplier_name']) ?></strong><?= e($supplier['address'] ?: 'No address on file') ?></td>
                                            <td><?= e($supplier['contact_person'] ?: '—') ?><?= $supplier['phone'] ? '<br>' . e($supplier['phone']) : '' ?><?= $supplier['email'] ? '<br>' . e($supplier['email']) : '' ?></td>
                                            <td><?= e((string) $supplier['item_count']) ?></td>
                                            <td class="money"><?= e(number_format((float) $supplier['supplied_value'], 2)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?><div class="empty">No suppliers recorded yet.</div><?php endif; ?>
                    </div>
                    <div>
                        <div class="section-title"><h2>Add or update a supplier</h2></div>
                        <form method="post" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="save_supplier">
                            <div class="form-grid">
                                <div class="span-2"><label>Supplier name</label><input name="supplier_name" placeholder="Company or trader name" required></div>
                                <div><label>Contact person</label><input name="contact_person"></div>
                                <div><label>Phone</label><input name="phone" placeholder="+250 7xx xxx xxx"></div>
                                <div class="span-2"><label>Email</label><input type="email" name="email" placeholder="name@company.rw"></div>
                                <div class="span-2"><label>Address</label><input name="address" placeholder="Street, city, country"></div>
                            </div>
                            <div class="form-actions"><button type="submit">Save supplier</button></div>
                        </form>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>