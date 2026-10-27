(function () {
    'use strict';

    const translations = {
        'Our central mission': 'Notre mission centrale',
        'Promoting academic excellence and diversity through education.': 'Promouvoir l’excellence scolaire et la diversité par l’éducation.',
        'Raising the voices of tomorrow through quality intercultural education, where every student is encouraged to become a global citizen.': 'Faire entendre les voix de demain grâce à une éducation interculturelle de qualité, où chaque élève est encouragé à devenir un citoyen du monde.',
        'Mandela International French School': 'École Internationale Française Mandela',
        'Sign in': 'Connexion',
        'Choose your role to continue to your secure workspace.': 'Choisissez votre rôle pour accéder à votre espace sécurisé.',
        'Language / Langue': 'Langue / Language',
        'Your role': 'Votre rôle',
        'Choose role': 'Choisir un rôle',
        'Password': 'Mot de passe',
        'Enter your assigned password': 'Saisissez le mot de passe qui vous a été attribué',
        'Continue to workspace': 'Accéder à votre espace',
        'Your access is limited to the responsibilities assigned to your role.': 'Votre accès est limité aux responsabilités associées à votre rôle.',
        'Invalid role or password.': 'Rôle ou mot de passe invalide.',
        'Dashboard': 'Tableau de bord',
        'Main Dashboard': 'Tableau de bord principal',
        'Sign out': 'Déconnexion',
        'Administrator': 'Administrateur',
        'Registrar': 'Secrétaire scolaire',
        'Teacher': 'Enseignant',
        'Accountant': 'Comptable',
        'Librarian': 'Bibliothécaire',
        'Parent': 'Parent',
        'Student': 'Élève',
        'Head Teacher/Principal': 'Directeur / Directrice',
        'Deputy Headteacher': 'Directeur adjoint / Directrice adjointe',
        'Administrator dashboard': 'Tableau de bord administrateur',
        'Manage accounts, access, school settings, audit activity, and database backups.': 'Gérez les comptes, les accès, les paramètres de l’école, les journaux d’activité et les sauvegardes de la base de données.',
        'Users & roles': 'Utilisateurs et rôles',
        'System settings': 'Paramètres du système',
        'Permissions': 'Autorisations',
        'System logs': 'Journaux du système',
        'Database backup': 'Sauvegarde de la base de données',
        'Students': 'Élèves',
        'User accounts': 'Comptes utilisateurs',
        'Defined roles': 'Rôles définis',
        'Recent audit events': 'Événements récents du journal',
        'Manage school users, role assignments, student records, settings, permissions, audit logs, and database backups. Changes are saved directly to the main database.': 'Gérez les utilisateurs de l’école, les rôles, les dossiers des élèves, les paramètres, les autorisations, les journaux et les sauvegardes. Les modifications sont enregistrées directement dans la base de données principale.',
        'Admission number': 'Numéro d’inscription',
        'First name': 'Prénom',
        'Last name': 'Nom',
        'Class': 'Classe',
        'Guardian name': 'Nom du responsable légal',
        'Guardian phone': 'Téléphone du responsable légal',
        'Update student': 'Modifier l’élève',
        'Add student': 'Ajouter un élève',
        'Save changes': 'Enregistrer les modifications',
        'Cancel': 'Annuler',
        'Student records': 'Dossiers des élèves',
        'Student': 'Élève',
        'Admission': 'Inscription',
        'Actions': 'Actions',
        'No guardian listed': 'Aucun responsable légal indiqué',
        'Unassigned': 'Non attribué',
        'Edit': 'Modifier',
        'Delete': 'Supprimer',
        'Update staff user': 'Modifier le compte du personnel',
        'Add staff user': 'Ajouter un membre du personnel',
        'Save role permissions': 'Enregistrer les autorisations des rôles',
        'Message': 'Message',
        'Current account': 'Compte actuel',
        'Manage Users': 'Gérer les utilisateurs',
        'Create accounts and assign staff roles.': 'Créez des comptes et attribuez des rôles au personnel.',
        'Manage Roles': 'Gérer les rôles',
        'Review responsibilities and access levels.': 'Consultez les responsabilités et les niveaux d’accès.',
        'Student Records': 'Dossiers des élèves',
        'View and maintain school records.': 'Consultez et gérez les dossiers de l’école.',
        'Exams & Results': 'Examens et résultats',
        'Create exams, validate marks, and review performance.': 'Créez des examens, validez les notes et consultez les résultats.',
        'System Settings': 'Paramètres du système',
        'Configure school identity and academic details.': 'Configurez l’identité de l’école et les informations scolaires.',
        'System Logs': 'Journaux du système',
        'Review recent sign-ins and administrator actions.': 'Consultez les connexions récentes et les actions des administrateurs.',
        'Database Backup': 'Sauvegarde de la base de données',
        'Download a secure SQL backup of school records.': 'Téléchargez une sauvegarde SQL sécurisée des dossiers de l’école.',
        'Student Registration': 'Inscription des élèves',
        'Register new students and update profiles.': 'Inscrivez de nouveaux élèves et mettez à jour leurs profils.',
        'Admissions': 'Admissions',
        'Track applications and enrolment.': 'Suivez les candidatures et les inscriptions.',
        'Class Assignment': 'Affectation aux classes',
        'Organise students by class.': 'Organisez les élèves par classe.',
        'Manage exam periods, subject allocation, and full result processing.': 'Gérez les périodes d’examen, l’attribution des matières et le traitement des résultats.',
        'My Classes': 'Mes classes',
        'See your assigned classes and students.': 'Consultez les classes et les élèves qui vous sont attribués.',
        'Attendance': 'Présence',
        'Record and review daily attendance.': 'Enregistrez et consultez les présences quotidiennes.',
        'Student Progress': 'Progrès des élèves',
        'Follow learning progress over time.': 'Suivez les progrès scolaires au fil du temps.',
        'Review student records for your classes.': 'Consultez les dossiers des élèves de vos classes.',
        'Library Catalog': 'Catalogue de la bibliothèque',
        'Manage the school book catalogue.': 'Gérez le catalogue de livres de l’école.',
        'Book Lending': 'Prêt de livres',
        'Record books borrowed by students and staff.': 'Enregistrez les livres empruntés par les élèves et le personnel.',
        'Returns': 'Retours',
        'Track returned books and outstanding loans.': 'Suivez les livres rendus et les prêts en cours.',
        'Library Reports': 'Rapports de la bibliothèque',
        'Review library stock and lending activity.': 'Consultez le stock et l’activité de prêt de la bibliothèque.',
        'Fee Payments': 'Paiements des frais scolaires',
        'Record and review fee transactions.': 'Enregistrez et consultez les paiements des frais scolaires.',
        'Receipts': 'Reçus',
        'Find and manage payment receipts.': 'Recherchez et gérez les reçus de paiement.',
        'Invoices': 'Factures',
        'Track outstanding school fees.': 'Suivez les frais scolaires impayés.',
        'Financial Reports': 'Rapports financiers',
        'Review payment summaries.': 'Consultez les récapitulatifs des paiements.',
        'My Profile': 'Mon profil',
        'View your personal information.': 'Consultez vos informations personnelles.',
        'Review attendance history.': 'Consultez l’historique des présences.',
        'Results': 'Résultats',
        'View academic results and progress.': 'Consultez les résultats scolaires et les progrès.',
        'Fee Status': 'État des frais scolaires',
        'Check current fee payments.': 'Consultez l’état actuel des paiements.',
        'Term Registration': 'Inscription au trimestre',
        'Submit your reporting and enrolment details.': 'Soumettez vos informations de suivi et d’inscription.',
        'My Attendance': 'Mes présences',
        'Review daily attendance and status.': 'Consultez vos présences quotidiennes et votre statut.',
        'My Payments': 'Mes paiements',
        'View payment history and receipts.': 'Consultez l’historique de vos paiements et vos reçus.',
        'View your published examination results.': 'Consultez les résultats d’examen publiés.',
        'Recent students': 'Élèves récemment inscrits',
        'Latest registrations': 'Dernières inscriptions',
        'Term enrollments': 'Inscriptions au trimestre',
        'waiting review': 'en attente de vérification',
        'Open registrar': 'Ouvrir l’espace du secrétariat',
        'Parents and guardians': 'Parents et responsables légaux',
        'Manage parent accounts': 'Gérer les comptes des parents',
        'Quick access': 'Accès rapide',
        'Available for your role': 'Disponible selon votre rôle',
        'Logged in as': 'Connecté en tant que',
        'Today': 'Aujourd’hui',
        'Overview': 'Vue d’ensemble',
        'Reports': 'Rapports',
        'Search': 'Rechercher',
        'Filter': 'Filtrer',
        'Save': 'Enregistrer',
        'Submit': 'Soumettre',
        'Update': 'Mettre à jour',
        'Create': 'Créer',
        'Add': 'Ajouter',
        'View': 'Consulter',
        'Open': 'Ouvert',
        'Close': 'Fermer',
        'Complete': 'Terminé',
        'Pending': 'En attente',
        'Validated': 'Validé',
        'Present': 'Présent',
        'Absent': 'Absent',
        'Late': 'En retard',
        'Date': 'Date',
        'Name': 'Nom',
        'Email': 'Adresse e-mail',
        'Phone': 'Téléphone',
        'Status': 'Statut',
        'Term': 'Trimestre',
        'Academic year': 'Année scolaire',
        'Subject': 'Matière',
        'Description': 'Description',
        'Notes': 'Notes',
        'Optional': 'Facultatif',
        'Select a student': 'Sélectionner un élève',
        'Select student': 'Sélectionner un élève',
        'Select an exam': 'Sélectionner un examen',
        'Select exam': 'Sélectionner un examen',
        'Select class': 'Sélectionner une classe',
        'No available exams match your timetable assignments. Ask the registrar to assign your class, subject, term and academic year in the timetable, create the exam, and assign its subject.': 'Aucun examen disponible ne correspond à votre emploi du temps. Demandez au secrétariat de vous attribuer une classe, une matière, un trimestre et une année scolaire, puis de créer l’examen et d’y associer la matière.',
        'Teacher workspace': 'Espace enseignant',
        'Enter student marks': 'Saisir les notes des élèves',
        'Exam, class and term': 'Examen, classe et trimestre',
        'Load students': 'Charger les élèves',
        'No students are currently enrolled in this class.': 'Aucun élève n’est actuellement inscrit dans cette classe.',
        'Record attendance': 'Enregistrer les présences',
        'Today’s attendance': 'Présences du jour',
        'Today\'s attendance': 'Présences du jour',
        'Save attendance': 'Enregistrer les présences',
        'Save marks as pending': 'Enregistrer les notes en attente',
        'Pending review': 'En attente de vérification',
        'Print receipt': 'Imprimer le reçu',
        'Print card': 'Imprimer la carte',
        'Back to dashboard': 'Retour au tableau de bord',
        'Back to portal': 'Retour au portail',
        'Back to finance': 'Retour à la comptabilité',
        'Payment receipt': 'Reçu de paiement',
        'Meal service record': 'Relevé du service de repas',
        'Teacher Workspace': 'Espace enseignant',
        'Registrar Workspace': 'Espace du secrétariat',
        'Administrator dashboard sections': 'Sections du tableau de bord administrateur',
        'Examination & Results': 'Examens et résultats',
        'Academic reports': 'Rapports scolaires',
        'Financial reports': 'Rapports financiers',
        'Outstanding balances by student': 'Soldes impayés par élève',
        'Collections by payment method': 'Encaissements par mode de paiement',
        'Student attendance': 'Présences des élèves',
        'Monitor staff': 'Suivi du personnel',
        'Manage discipline': 'Gérer la discipline',
        'Incident date': 'Date de l’incident',
        'Incident details': 'Détails de l’incident',
        'Record incident': 'Enregistrer l’incident',
        'Monitor academic activities': 'Suivre les activités scolaires',
        'Exam title': 'Titre de l’examen',
        'Create exam': 'Créer un examen',
        'Validate selected marks': 'Valider les notes sélectionnées',
        'Payment date': 'Date du paiement',
        'Student fee assessments': 'Évaluations des frais scolaires',
        'Regenerate': 'Générer à nouveau',
        'Inventory item': 'Article en stock',
        'Supplier': 'Fournisseur',
        'Stock condition': 'État du stock',
        'Opening quantity': 'Quantité initiale',
        'Save supplier': 'Enregistrer le fournisseur',
        'Add timetable entry': 'Ajouter un créneau à l’emploi du temps',
        'Attendance date': 'Date de présence',
        'Notes (optional)': 'Notes (facultatif)',
        'Title': 'Titre',
        'Category': 'Catégorie',
        'Quantity': 'Quantité',
        'Available book': 'Livre disponible',
        'Due date': 'Date de retour prévue',
        'Issue books': 'Prêter des livres',
        'Issue book': 'Prêter le livre',
        'Lost books': 'Livres perdus',
        'Library Management': 'Gestion de la bibliothèque',
        'Search books': 'Rechercher des livres',
        'Returns and active loans': 'Retours et prêts en cours',
        'Mark lost': 'Signaler comme perdu',
        'Author': 'Auteur',
        'ISBN': 'ISBN',
        'Add book': 'Ajouter un livre',
        'Full name': 'Nom complet',
        'Temporary password': 'Mot de passe temporaire',
        'Role': 'Rôle',
        'Email': 'Adresse e-mail',
        'Save system settings': 'Enregistrer les paramètres du système',
        'Role permissions': 'Autorisations des rôles',
        'Download full database backup (.sql)': 'Télécharger la sauvegarde complète de la base de données (.sql)',
        'School communications': 'Communications de l’école',
        'Notice title': 'Titre de l’annonce',
        'Audience': 'Public',
        'Everyone': 'Tout le monde',
        'Publish communication': 'Publier la communication',
        'Staff accounts': 'Comptes du personnel',
        'ICT Admin': 'Administration informatique',
        'Student registry': 'Registre des élèves',
        'View date': 'Afficher la date',
        'Check-out time (optional)': 'Heure de départ (facultative)',
        'Manually add a student': 'Ajouter manuellement un élève',
        'Student email': 'Adresse e-mail de l’élève',
        'Term enrollment submissions': 'Demandes d’inscription au trimestre',
        'Timetable entries': 'Créneaux de l’emploi du temps',
        'End time': 'Heure de fin',
        'Teacher': 'Enseignant',
        'School leadership': 'Direction de l’école',
        'Head Teacher Dashboard': 'Tableau de bord de la direction',
        'Deputy Headteacher Dashboard': 'Tableau de bord du directeur adjoint',
        'Monitor students': 'Suivre les élèves',
        'View attendance': 'Consulter les présences',
        'Incident type': 'Type d’incident',
        'Action taken / follow-up': 'Mesure prise / suivi',
        'Academic results': 'Résultats scolaires',
        'Welcome,': 'Bienvenue,',
        'Student payment details': 'Détails des paiements de l’élève',
        'Student meal card': 'Carte de repas de l’élève',
        'Term registration and reporting': 'Inscription et suivi du trimestre',
        'Submit term registration': 'Soumettre l’inscription au trimestre',
        'My term registrations': 'Mes inscriptions au trimestre',
        'Recent attendance': 'Présences récentes',
        'Recent payments': 'Paiements récents',
        'No attendance records yet.': 'Aucun relevé de présence pour le moment.',
        'No payment records yet.': 'Aucun relevé de paiement pour le moment.',
        'No attendance has been recorded today.': 'Aucune présence n’a été enregistrée aujourd’hui.',
        'No attendance has been recorded yet.': 'Aucune présence n’a encore été enregistrée.',
        'No payment has been recorded yet.': 'Aucun paiement n’a encore été enregistré.',
        'There are no new school communications at this time.': 'Il n’y a pas de nouvelle communication de l’école pour le moment.',
        'Not provided': 'Non renseigné',
        'What you can access': 'Vos accès',
        'Transaction ledger': 'Registre des transactions',
        'Receipt number': 'Numéro de reçu',
        'Student pay codes': 'Codes de paiement des élèves',
        'Inventory and stores': 'Stocks et fournitures',
        'Save fee assessment': 'Enregistrer l’évaluation des frais',
        'Invoice, GRN or document no.': 'Facture, bon de réception ou numéro de document',
        'Reorder alerts': 'Alertes de réapprovisionnement',
        'item(s) need attention': 'article(s) nécessitent une attention',
        'Stock records': 'Registres de stock',
        'item(s) matching the filter': 'article(s) correspondant au filtre',
        'Auto-generated if blank': 'Généré automatiquement si laissé vide',
        'e.g. Store room A, shelf 3': 'ex. Réserve A, étagère 3',
        'Current quantity on hand': 'Quantité actuellement en stock',
        'Contact person': 'Personne à contacter',
        'Create exam': 'Créer un examen',
        'Add subject': 'Ajouter une matière',
        'Subject code': 'Code de la matière',
        'Save subject': 'Enregistrer la matière',
        'Enter marks': 'Saisir les notes',
        'Save marks': 'Enregistrer les notes',
        'Class subject performance': 'Résultats par matière et par classe',
        'Financial reports': 'Rapports financiers',
        'Payment history': 'Historique des paiements',
        'Monthly collections': 'Encaissements mensuels',
        'Approve academic reports': 'Approuver les rapports scolaires',
        'Academic performance': 'Résultats scolaires',
        'Staff attendance': 'Présences du personnel',
        'Main Dashboard': 'Tableau de bord principal',
        'Search name, admission no., receipt, or method…': 'Rechercher un nom, un numéro d’inscription, un reçu ou un mode de paiement…',
        'Filter': 'Filtrer',
        'No available books': 'Aucun livre disponible',
        'No records found.': 'Aucun résultat.',
        'No results found.': 'Aucun résultat.',
        'Select a date': 'Sélectionner une date',
        'Select a role': 'Sélectionner un rôle',
        'Select a class': 'Sélectionner une classe',
        'Please select': 'Veuillez sélectionner',
        'Save changes': 'Enregistrer les modifications',
        'Save attendance': 'Enregistrer les présences',
        'Back': 'Retour',
        'Print': 'Imprimer',
        'Download PDF': 'Télécharger le PDF',
        'Download': 'Télécharger',
        'All': 'Tous',
        'All students': 'Tous les élèves',
        'All classes': 'Toutes les classes',
        'All terms': 'Tous les trimestres',
        'No': 'Non',
        'Yes': 'Oui',
        'None': 'Aucun',
        'Available': 'Disponible',
        'Issued': 'Prêté',
        'Returned': 'Rendu',
        'Overdue': 'En retard',
        'Paid': 'Payé',
        'Unpaid': 'Impayé',
        'Published': 'Publié',
        'Draft': 'Brouillon',
        'Closed': 'Fermé',
        'Registrar workspace': 'Espace du secrétariat',
        'Manage admissions, enrollment reviews, class assignments, and the school timetable from one place.': 'Gérez les admissions, les demandes d’inscription, les affectations aux classes et l’emploi du temps depuis un seul espace.',
        'Registered students': 'Élèves inscrits',
        'Enrollment records': 'Dossiers d’inscription',
        'Awaiting review': 'En attente de vérification',
        'Approved': 'Approuvé',
        'Rejected': 'Rejeté',
        'Student portal credentials': 'Identifiants du portail élève',
        'Review teacher attendance captured from the attendance machine and enter records manually when needed.': 'Consultez les présences des enseignants enregistrées par la pointeuse et ajoutez manuellement les relevés si nécessaire.',
        'Records': 'Relevés',
        'Check in': 'Arrivée',
        'Check out': 'Départ',
        'Source': 'Source',
        'Recorded by': 'Enregistré par',
        'No staff attendance records for this date.': 'Aucun relevé de présence du personnel pour cette date.',
        'Manually record teacher attendance': 'Enregistrer manuellement la présence d’un enseignant',
        'Use this when a teacher’s attendance was not captured by the machine. Duplicate teacher/date records are rejected to protect captured attendance.': 'Utilisez cette option si la présence d’un enseignant n’a pas été enregistrée par la pointeuse. Les doublons pour un même enseignant et une même date sont refusés.',
        'Select teacher': 'Sélectionner un enseignant',
        'Check-in time (optional)': 'Heure d’arrivée (facultative)',
        'Check-out time (optional)': 'Heure de départ (facultative)',
        'Record teacher attendance': 'Enregistrer la présence de l’enseignant',
        'Use this for students who have not registered through the Student Portal.': 'Utilisez cette option pour les élèves qui ne se sont pas inscrits via le portail élève.',
        'Create Student Portal account': 'Créer un compte du portail élève',
        'Total collected': 'Total encaissé',
        'transactions': 'transactions',
        'Today’s collection': 'Encaissement du jour',
        'Today\'s collection': 'Encaissement du jour',
        'Total transactions': 'Nombre total de transactions',
        'All time': 'Depuis le début',
        'Last payment date': 'Date du dernier paiement',
        'Most recent': 'Le plus récent',
        'Cash': 'Espèces',
        'Mobile Money': 'Paiement mobile',
        'Bank': 'Banque',
        'Pay Code': 'Code de paiement',
        'Finance & Transactions': 'Finances et transactions',
        'Record, adjust, and review student fee payments.': 'Enregistrez, modifiez et consultez les paiements des frais scolaires.',
        'Adjust payment': 'Modifier le paiement',
        'Record payment': 'Enregistrer un paiement',
        'Amount': 'Montant',
        'Payment method': 'Mode de paiement',
        'Verify the student and school particulars below before confirming a payment against this code. The pay code is the payment reference; this form records a payment after receipt and does not initiate a bank or mobile-money transfer.': 'Vérifiez les informations de l’élève et de l’école ci-dessous avant de confirmer un paiement avec ce code. Le code sert de référence de paiement ; ce formulaire enregistre un paiement reçu et ne déclenche aucun transfert bancaire ou mobile.',
        'Save adjustment': 'Enregistrer la modification',
        'Total fees assessed': 'Total des frais évalués',
        'Fees collected': 'Frais encaissés',
        'Outstanding balances': 'Soldes impayés',
        'Payment transactions': 'Transactions de paiement',
        'No outstanding fee balances are currently recorded.': 'Aucun solde de frais impayés n’est actuellement enregistré.',
        'No payment transactions have been recorded.': 'Aucune transaction de paiement n’a été enregistrée.',
        'Monthly collections': 'Encaissements mensuels',
        'Collections recorded during the last 12 months.': 'Encaissements enregistrés au cours des 12 derniers mois.',
        'No collections were recorded during the last 12 months.': 'Aucun encaissement n’a été enregistré au cours des 12 derniers mois.',
        'No payment methods have collection records.': 'Aucun encaissement n’est enregistré pour un mode de paiement.',
        'School leadership': 'Direction de l’école',
        'Monitor school performance, attendance, staff and students, and approve completed academic reports.': 'Suivez les résultats de l’école, les présences, le personnel et les élèves, et approuvez les rapports scolaires terminés.',
        'Staff accounts': 'Comptes du personnel',
        'Exams': 'Examens',
        'Published reports': 'Rapports publiés',
        'Student attendance records today': 'Présences des élèves aujourd’hui',
        'Staff attendance records today': 'Présences du personnel aujourd’hui',
        'Reports ready to approve': 'Rapports prêts à être approuvés',
        'Read-only financial oversight from recorded student fee assessments and payment transactions. Outstanding balances are calculated per student across all assessed terms, so one student’s overpayment does not reduce another student’s balance.': 'Consultation seule des évaluations des frais scolaires et des paiements enregistrés. Les soldes impayés sont calculés pour chaque élève et chaque trimestre ; le trop-perçu d’un élève ne réduit donc pas le solde d’un autre.',
        'Export financial report': 'Exporter le rapport financier',
        'Export to Excel': 'Exporter vers Excel',
        'Export CSV': 'Exporter en CSV',
        'Print report': 'Imprimer le rapport',
        'Fees assessed': 'Frais évalués',
        'Paid': 'Payé',
        'Outstanding': 'Impayé',
        'Receipt / Method': 'Reçu / Mode',
        'Account removed': 'Compte supprimé',
        'Showing the 30 students with the highest outstanding balances.': 'Affichage des 30 élèves ayant les soldes impayés les plus élevés.',
        'Showing the 25 most recent payment transactions.': 'Affichage des 25 transactions de paiement les plus récentes.',
        'Reports can be approved once every student has a validated mark for each exam subject. Approval publishes results to student and parent accounts.': 'Les rapports peuvent être approuvés lorsque chaque élève a une note validée pour chaque matière. L’approbation publie les résultats dans les comptes des élèves et des parents.',
        'Your personal school information, attendance, and payment history.': 'Vos informations scolaires personnelles, vos présences et votre historique de paiements.',
        'Attendance rate': 'Taux de présence',
        'Days present': 'Jours de présence',
        'Attendance records': 'Relevés de présence',
        'Payments made': 'Paiements effectués',
        'My profile': 'Mon profil',
        'Fee status': 'État des frais scolaires',
        'Total paid': 'Total payé',
        'Payment records': 'Relevés de paiement',
        'Payments are recorded and adjusted by the Accounts Office. Contact the Accounts Officer if a payment or receipt needs correction.': 'Les paiements sont enregistrés et modifiés par le service comptable. Contactez-le si un paiement ou un reçu doit être corrigé.',
        'Daily attendance': 'Présences quotidiennes',
        'Receipt': 'Reçu',
        'The Registrar has released these examination reports for your child.': 'Le secrétariat a publié ces rapports d’examen pour votre enfant.',
        'Examination': 'Examen',
        'Total': 'Total',
        'Average': 'Moyenne',
        'Report': 'Rapport',
        'View full report': 'Consulter le rapport complet',
        'Published results for your child\'s student record will appear here.': 'Les résultats publiés pour le dossier scolaire de votre enfant apparaîtront ici.',
        'This portal is read-only for your personal records. You can view your profile, daily attendance, fee payments, receipts, and current attendance summary. Teachers record attendance, while the Accounts Office records and adjusts payments.': 'Ce portail permet uniquement de consulter vos dossiers personnels. Vous pouvez consulter votre profil, vos présences quotidiennes, vos paiements, vos reçus et le récapitulatif de vos présences. Les enseignants enregistrent les présences et le service comptable gère les paiements.',
        'Register for each term, report to school, and follow your personal records.': 'Inscrivez-vous à chaque trimestre, signalez votre arrivée à l’école et consultez vos dossiers personnels.',
        'Your Accounts Office transaction has been confirmed. Your meal card is now available to download and print.': 'Votre transaction auprès du service comptable a été confirmée. Votre carte de repas peut maintenant être téléchargée et imprimée.',
        'Use the pay code as the payment reference. Before confirming with your bank, mobile-money provider, or Accounts Office, verify that the recipient details show this school and student.': 'Utilisez le code de paiement comme référence. Avant de confirmer auprès de votre banque, de votre service de paiement mobile ou du service comptable, vérifiez que le bénéficiaire indiqué est bien cette école et cet élève.',
        'If the payment screen does not show these particulars after entering the pay code, do not complete the transfer; contact the Accounts Office. The school system records payments after receipt and does not itself initiate bank or mobile-money transfers.': 'Si ces informations ne s’affichent pas après la saisie du code de paiement, n’effectuez pas le transfert et contactez le service comptable. Le système de l’école enregistre les paiements reçus, mais ne déclenche aucun transfert bancaire ou mobile.',
        'Card status': 'État de la carte',
        'Scan with a phone camera connected to the school network': 'Scannez avec un téléphone connecté au réseau de l’école',
        'Download / print meal card': 'Télécharger / imprimer la carte de repas',
        'Meal card downloaded on': 'Carte de repas téléchargée le',
        'Meal card has not been downloaded yet.': 'La carte de repas n’a pas encore été téléchargée.',
        'The meal card will become available after the Accounts Office records a payment transaction for your student account.': 'La carte de repas sera disponible après l’enregistrement d’un paiement sur votre compte élève par le service comptable.',
        'No qualifying Accounts Office transaction found yet.': 'Aucune transaction admissible du service comptable n’a encore été trouvée.',
        'Registrations submitted': 'Inscriptions soumises',
        'Submit this form when you report for a new term. ICT or the Registrar can review your submission.': 'Soumettez ce formulaire à votre arrivée pour un nouveau trimestre. Le service informatique ou le secrétariat pourra examiner votre demande.',
        'Choose term': 'Choisir un trimestre',
        'Reporting date': 'Date de rentrée',
        'Additional information': 'Informations complémentaires',
        'Transport, accommodation, health, or other information for the school': 'Transport, hébergement, santé ou toute autre information utile à l’école',
        'No term registration submitted yet.': 'Aucune inscription au trimestre n’a encore été soumise.',
        'Examination reports published by the Registrar.': 'Rapports d’examen publiés par le secrétariat.',
        'Not assigned': 'Non attribué',
        'Leave': 'Congé',
        'Machine': 'Pointeuse',
        'Attendance machine': 'Pointeuse',
        'Not available': 'Indisponible',
        'Exam title': 'Titre de l’examen',
        'Pay code': 'Code de paiement',
        'Invoice': 'Facture',
        'Reference': 'Référence',
        'Item code': 'Code de l’article',
        'Storage location': 'Emplacement de stockage',
        'Save item': 'Enregistrer l’article',
        'Save supplier': 'Enregistrer le fournisseur',
        'Email': 'Adresse e-mail',
        'No records found': 'Aucun résultat',
        'Sunday': 'Dimanche',
        'Monday': 'Lundi',
        'Tuesday': 'Mardi',
        'Wednesday': 'Mercredi',
        'Thursday': 'Jeudi',
        'Friday': 'Vendredi',
        'Saturday': 'Samedi',
        'Actions': 'Actions',
        'Dashboard | Mandela International French School IMS': 'Tableau de bord | École Internationale Française Mandela IMS',
        'ICT Administration | MIFS IMS': 'Administration informatique | MIFS IMS',
        'Registrar Workspace | MIFS IMS': 'Espace du secrétariat | MIFS IMS',
        'Teacher Workspace | MIFS IMS': 'Espace enseignant | MIFS IMS',
        'Payment receipt': 'Reçu de paiement',
        'Meal service record': 'Relevé du service de repas',
        'ICT Administration': 'Administration informatique',
        'Administrator Workspace': 'Espace administrateur',
        'Registrar dashboard': 'Tableau de bord du secrétariat',
        'Finance dashboard': 'Tableau de bord financier',
        'Inventory dashboard': 'Tableau de bord des stocks'
    };

    const translatedPrefixes = [
        ['Welcome, ', 'Bienvenue, '],
        ['Good day, ', 'Bonjour, ']
    ];
    const originalText = new WeakMap();
    const originalAttributes = new WeakMap();
    const languageSelectId = 'login-language';

    function translated(value, language) {
        if (language !== 'fr') {
            return value;
        }
        const trimmed = value.trim();
        const normalized = trimmed.replace(/\s+/g, ' ');
        let result = translations[normalized];
        if (!result) {
            const prefix = translatedPrefixes.find(function (entry) {
                return normalized.startsWith(entry[0]);
            });
            if (prefix) {
                result = prefix[1] + normalized.slice(prefix[0].length);
            }
        }
        if (!result) {
            return value;
        }
        const leading = value.match(/^\s*/)[0];
        const trailing = value.match(/\s*$/)[0];
        return leading + result + trailing;
    }

    function translateDocument(language) {
        document.documentElement.lang = language;
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            if (!originalText.has(node)) {
                originalText.set(node, node.nodeValue);
            }
            node.nodeValue = translated(originalText.get(node) || '', language);
        }

        const title = document.querySelector('title');
        if (title && title.firstChild && title.firstChild.nodeType === Node.TEXT_NODE) {
            const titleText = title.firstChild;
            if (!originalText.has(titleText)) {
                originalText.set(titleText, titleText.nodeValue);
            }
            titleText.nodeValue = translated(originalText.get(titleText) || '', language);
        }

        document.querySelectorAll('[placeholder], [title], [aria-label], [alt], input[type="button"], input[type="submit"], input[type="reset"]').forEach(function (element) {
            const attributes = ['placeholder', 'title', 'aria-label', 'alt'];
            if (element.matches('input[type="button"], input[type="submit"], input[type="reset"]')) {
                attributes.push('value');
            }
            attributes.forEach(function (attribute) {
                if (!element.hasAttribute(attribute)) {
                    return;
                }
                let originals = originalAttributes.get(element);
                if (!originals) {
                    originals = {};
                    originalAttributes.set(element, originals);
                }
                if (!(attribute in originals)) {
                    originals[attribute] = element.getAttribute(attribute);
                }
                element.setAttribute(attribute, translated(originals[attribute], language));
            });
        });
    }

    function saveLanguage(language, reload) {
        translateDocument(language);
        fetch('set_language.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams({ language: language })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('The language preference could not be saved.');
            }
            return response.json();
        }).then(function (result) {
            if (!result || result.language !== language) {
                throw new Error('The language preference could not be confirmed.');
            }
            if (reload) {
                window.location.reload();
            }
        }).catch(function (error) {
            window.alert(error.message || 'The language preference could not be saved.');
        });
    }

    function addLanguageControl() {
        const loginControl = document.getElementById(languageSelectId);
        if (!loginControl || !loginControl.matches('fieldset')) {
            return;
        }

        const options = loginControl.querySelectorAll('input[name="language"]');
        options.forEach(function (option) {
            option.checked = option.value === (document.documentElement.lang === 'fr' ? 'fr' : 'en');
            option.addEventListener('change', function () {
                if (option.checked) {
                    saveLanguage(option.value, false);
                }
            });
        });
    }

    translateDocument(document.documentElement.lang);
    addLanguageControl();

    new MutationObserver(function (records) {
        records.forEach(function (record) {
            record.addedNodes.forEach(function (added) {
                if (added.nodeType === Node.TEXT_NODE) {
                    const original = added.nodeValue;
                    originalText.set(added, original);
                    added.nodeValue = translated(original, document.documentElement.lang);
                } else if (added.nodeType === Node.ELEMENT_NODE) {
                    const walker = document.createTreeWalker(added, NodeFilter.SHOW_TEXT);
                    let textNode;
                    while ((textNode = walker.nextNode())) {
                        const original = textNode.nodeValue;
                        originalText.set(textNode, original);
                        textNode.nodeValue = translated(original, document.documentElement.lang);
                    }
                }
            });
        });
    }).observe(document.body, { childList: true, subtree: true });
}());
