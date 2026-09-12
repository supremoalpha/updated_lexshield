<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();

function lex_client_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'CL';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'CL';
}

function lex_client_initial_tone(string $name): string
{
    $tones = ['blue', 'green', 'purple', 'orange', 'cyan', 'indigo', 'gold'];
    $index = abs(crc32(strtolower(trim($name)))) % count($tones);
    return $tones[$index];
}

function lex_client_risk_class(string $level): string
{
    return match (strtolower(trim($level))) {
        'high' => 'is-high',
        'medium' => 'is-medium',
        default => 'is-low',
    };
}

function lex_manage_clients_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute(['table' => $table]);
    return $cache[$table] = (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    lex_audit_csrf_failure('admin/manage_clients.php');
    lex_flash_set('error', 'Invalid CSRF token.');
    header('Location: ' . lex_app_url('admin/manage_clients.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
            $email = lex_sanitize_email($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $contactNumber = lex_sanitize_text($_POST['contact_number'] ?? '');
            $address = lex_sanitize_text($_POST['address'] ?? '');
            $riskLevel = strtolower(lex_sanitize_text($_POST['risk_level'] ?? 'low'));
            if (!in_array($riskLevel, ['low', 'medium', 'high'], true)) {
                $riskLevel = 'low';
            }

            if ($fullName === '' || $email === '' || $contactNumber === '') {
                throw new RuntimeException('Please complete all required fields.');
            }

            $passwordError = lex_password_policy_error($password, $email, $fullName);
            if ($passwordError !== '') {
                throw new RuntimeException($passwordError);
            }

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('An account already exists with that email.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, created_at) VALUES (:full_name, :email, :password_hash, "client", 1, NOW())');
            $stmt->execute([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ]);
            $userId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO clients (user_id, contact_number, address, risk_level) VALUES (:user_id, :contact_number, :address, :risk_level)');
            $stmt->execute([
                'user_id' => $userId,
                'contact_number' => $contactNumber,
                'address' => $address,
                'risk_level' => $riskLevel,
            ]);
            $pdo->commit();
            lex_audit('add_client', 'clients', (string) $userId);
            lex_flash_set('success', 'Client added successfully.');
            header('Location: ' . lex_app_url('admin/manage_clients.php'));
            exit;
        } elseif ($action === 'change_password') {
            $targetUserId = lex_sanitize_int($_POST['target_user_id'] ?? 0);
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_new_password'] ?? '');

            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('New password and confirmation do not match.');
            }

            $emailSent = lex_admin_change_user_password($targetUserId, 'client', $newPassword);
            lex_flash_set(
                $emailSent ? 'success' : 'warning',
                $emailSent
                    ? 'Client password changed successfully and the new temporary password was emailed.'
                    : 'Client password changed successfully, but the email notification could not be sent.'
            );
            header('Location: ' . lex_app_url('admin/manage_clients.php'));
            exit;
        } elseif ($action === 'update_risk') {
            $clientId = lex_sanitize_int($_POST['client_id'] ?? 0);
            $riskLevel = $_POST['risk_level'] ?? 'low';
            $pdo->prepare('UPDATE clients SET risk_level = :risk_level WHERE id = :id')->execute(['risk_level' => $riskLevel, 'id' => $clientId]);
            lex_audit('update_client_risk', 'clients', (string) $clientId);
            lex_flash_set('success', 'Client risk level updated.');
            header('Location: ' . lex_app_url('admin/manage_clients.php'));
            exit;
        } elseif ($action === 'delete_client') {
            $clientId = lex_sanitize_int($_POST['client_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT c.id, c.user_id, u.full_name FROM clients c JOIN users u ON u.id = c.user_id WHERE c.id = :id LIMIT 1');
            $stmt->execute(['id' => $clientId]);
            $client = $stmt->fetch();
            if ($client) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $pdo->beginTransaction();
                try {
                    if (lex_manage_clients_table_exists($pdo, 'case_files')) {
                        $caseFiles = lex_recent('SELECT folder_name FROM case_files WHERE client_user_id = :client_user_id', ['client_user_id' => (int) $client['user_id']]);
                        foreach ($caseFiles as $caseFile) {
                            $folderName = (string) ($caseFile['folder_name'] ?? '');
                            if ($folderName !== '') {
                                lex_case_files_recursive_delete(lex_case_files_folder_path($folderName));
                            }
                        }
                    }
                    if (lex_manage_clients_table_exists($pdo, 'risk_assessments')) {
                        $pdo->prepare('DELETE FROM risk_assessments WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'appointments')) {
                        $pdo->prepare('DELETE FROM appointments WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'lawyer_reviews')) {
                        $pdo->prepare('DELETE FROM lawyer_reviews WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'case_files')) {
                        $pdo->prepare('DELETE FROM case_files WHERE client_user_id = :client_user_id_1 OR created_by_user_id = :client_user_id_2 OR updated_by_user_id = :client_user_id_3')->execute([
                            'client_user_id_1' => (int) $client['user_id'],
                            'client_user_id_2' => (int) $client['user_id'],
                            'client_user_id_3' => (int) $client['user_id'],
                        ]);
                    }
                    if (lex_manage_clients_table_exists($pdo, 'documents')) {
                        try {
                            $pdo->prepare('DELETE FROM documents WHERE case_id IN (SELECT id FROM cases WHERE client_id = :client_id)')->execute(['client_id' => $clientId]);
                        } catch (Throwable $docError) {
                            // Some local databases have a broken or partially dropped documents table; continue with the client delete.
                        }
                    }
                    $pdo->prepare('DELETE FROM cases WHERE client_id = :client_id')->execute(['client_id' => $clientId]);
                    $pdo->prepare('DELETE FROM clients WHERE id = :id')->execute(['id' => $clientId]);
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) $client['user_id']]);
                    $pdo->commit();
                    lex_audit('delete_client', 'clients', (string) $clientId);
                    lex_flash_set('success', 'Client removed.');
                    header('Location: ' . lex_app_url('admin/manage_clients.php'));
                    exit;
                } catch (Throwable $inner) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $inner;
                } finally {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                }
            } else {
                lex_flash_set('error', 'Client not found.');
                header('Location: ' . lex_app_url('admin/manage_clients.php'));
                exit;
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        lex_flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Could not update client record.');
        header('Location: ' . lex_app_url('admin/manage_clients.php'));
        exit;
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$riskFilter = strtolower(trim((string) ($_GET['risk'] ?? 'all')));
if (!in_array($riskFilter, ['all', 'low', 'medium', 'high'], true)) {
    $riskFilter = 'all';
}

$clientWhere = [];
$clientParams = [];

if ($search !== '') {
    $searchValue = '%' . $search . '%';
    $clientWhere[] = '(u.full_name LIKE :search_name OR u.email LIKE :search_email OR c.contact_number LIKE :search_phone OR c.address LIKE :search_address)';
    $clientParams['search_name'] = $searchValue;
    $clientParams['search_email'] = $searchValue;
    $clientParams['search_phone'] = $searchValue;
    $clientParams['search_address'] = $searchValue;
}

if ($riskFilter !== 'all') {
    $clientWhere[] = 'c.risk_level = :risk_level';
    $clientParams['risk_level'] = $riskFilter;
}

$perPage = 10;
$clientFrom = ' FROM clients c JOIN users u ON u.id = c.user_id';
$clientFilterSql = '';
if ($clientWhere) {
    $clientFilterSql = ' WHERE ' . implode(' AND ', $clientWhere);
}
$totalFilteredClients = lex_stats('SELECT COUNT(*)' . $clientFrom . $clientFilterSql, $clientParams);
$totalClientPages = max(1, (int) ceil($totalFilteredClients / $perPage));
$currentPage = min(max(1, lex_sanitize_int($_GET['page'] ?? 1)), $totalClientPages);
$offset = ($currentPage - 1) * $perPage;

$clientQuery = 'SELECT c.*, u.full_name, u.email, u.id AS user_id, u.avatar_stored_name' . $clientFrom . $clientFilterSql . ' ORDER BY c.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$clients = lex_recent($clientQuery, $clientParams);
$assessments = lex_recent('SELECT ra.*, u.full_name FROM risk_assessments ra JOIN clients c ON c.id = ra.client_id JOIN users u ON u.id = c.user_id ORDER BY ra.assessed_at DESC');
$totalClients = lex_stats('SELECT COUNT(*) FROM clients');
$activeCases = lex_stats("SELECT COUNT(*) FROM cases WHERE status IN ('open','ongoing')");
$highRiskClients = lex_stats("SELECT COUNT(*) FROM clients WHERE risk_level = 'high'");

lex_page_header('Manage Clients', 'clients');
?>
  <section class="admin-clients-stats" aria-label="Client summary">
    <article class="admin-clients-stat-card">
      <div class="admin-clients-stat-icon tone-blue" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 12a3.5 3.5 0 1 0-3.5-3.5A3.5 3.5 0 0 0 12 12Zm-6.5 7a.75.75 0 0 1-.75-.75c0-2.55 3.18-4.75 7.25-4.75s7.25 2.2 7.25 4.75a.75.75 0 0 1-1.5 0c0-1.53-2.42-3.25-5.75-3.25s-5.75 1.72-5.75 3.25A.75.75 0 0 1 5.5 19Z" fill="currentColor"/></svg>
      </div>
      <div class="admin-clients-stat-copy">
        <span>Total Clients</span>
        <strong><?= number_format($totalClients) ?></strong>
      </div>
    </article>
    <article class="admin-clients-stat-card">
      <div class="admin-clients-stat-icon tone-green" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M5.75 4h4.12c.5 0 .98.2 1.33.56l1.09 1.11c.07.07.17.11.27.11h5.69A1.75 1.75 0 0 1 20 7.53v10.72A1.75 1.75 0 0 1 18.25 20h-12.5A1.75 1.75 0 0 1 4 18.25V5.75A1.75 1.75 0 0 1 5.75 4Z" fill="currentColor"/></svg>
      </div>
      <div class="admin-clients-stat-copy">
        <span>Active Cases</span>
        <strong class="tone-success"><?= number_format($activeCases) ?></strong>
      </div>
    </article>
    <article class="admin-clients-stat-card">
      <div class="admin-clients-stat-icon tone-red" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm.75 4h-1.5v6.2h1.5v-6.2Zm0 8.1h-1.5v1.7h1.5v-1.7Z" fill="currentColor"/></svg>
      </div>
      <div class="admin-clients-stat-copy">
        <span>High Risk</span>
        <strong class="tone-danger"><?= number_format($highRiskClients) ?></strong>
      </div>
    </article>
  </section>

  <section class="card admin-clients-directory-card">
    <div class="admin-clients-directory-head">
      <div>
        <h2>Client Directory</h2>
        <p>Manage and view all clients in your organization</p>
      </div>
      <form method="get" class="admin-clients-filters">
        <label class="admin-clients-search">
          <span class="sr-only">Search clients</span>
          <span class="admin-clients-field-icon" aria-hidden="true"></span>
          <input type="search" name="q" value="<?= lex_e($search) ?>" placeholder="Search clients...">
        </label>
        <label class="admin-clients-risk-filter">
          <span class="sr-only">Filter by risk level</span>
          <select name="risk" onchange="this.form.submit()">
            <option value="all"<?= $riskFilter === 'all' ? ' selected' : '' ?>>All Risk Levels</option>
            <option value="low"<?= $riskFilter === 'low' ? ' selected' : '' ?>>Low Risk</option>
            <option value="medium"<?= $riskFilter === 'medium' ? ' selected' : '' ?>>Medium Risk</option>
            <option value="high"<?= $riskFilter === 'high' ? ' selected' : '' ?>>High Risk</option>
          </select>
        </label>
        <button class="button admin-clients-add-button admin-clients-toolbar-button" type="button" data-client-create-open>
          <span aria-hidden="true">+</span>
          <span>Add Client</span>
        </button>
      </form>
    </div>

    <div class="table-wrap">
      <table class="data-table admin-clients-table">
        <thead><tr><th>Client</th><th>Contact</th><th>Risk Level</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($clients as $client): ?>
          <?php
            $riskClass = lex_client_risk_class((string) ($client['risk_level'] ?? 'low'));
            $avatarUrl = lex_profile_avatar_url((string) ($client['avatar_stored_name'] ?? ''));
            $initials = lex_client_initials((string) $client['full_name']);
            $tone = lex_client_initial_tone((string) $client['full_name']);
          ?>
          <tr>
            <td data-label="Client">
              <div class="admin-clients-person">
                <?php if ($avatarUrl !== ''): ?>
                  <img class="admin-clients-avatar" src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e((string) $client['full_name']) ?>">
                <?php else: ?>
                  <span class="admin-clients-avatar admin-clients-avatar--<?= lex_e($tone) ?>" aria-hidden="true"><?= lex_e($initials) ?></span>
                <?php endif; ?>
                <div>
                  <button
                    class="admin-directory-name-button"
                    type="button"
                    data-client-profile-open
                    data-profile-name="<?= lex_e((string) $client['full_name']) ?>"
                    data-profile-user-id="<?= (int) $client['user_id'] ?>"
                    data-profile-email="<?= lex_e((string) $client['email']) ?>"
                    data-profile-phone="<?= lex_e((string) ($client['contact_number'] ?: 'No phone number')) ?>"
                    data-profile-address="<?= lex_e((string) ($client['address'] ?: 'No address provided')) ?>"
                    data-profile-risk="<?= lex_e(ucfirst((string) ($client['risk_level'] ?? 'low'))) ?>"
                    data-profile-risk-class="<?= lex_e($riskClass) ?>"
                    data-profile-avatar="<?= lex_e($avatarUrl) ?>"
                    data-profile-initials="<?= lex_e($initials) ?>"
                    data-profile-tone="<?= lex_e($tone) ?>"
                    aria-label="View profile for <?= lex_e((string) $client['full_name']) ?>"
                  ><?= lex_e($client['full_name']) ?></button>
                  <span><?= lex_e((string) ($client['address'] ?: 'No address provided')) ?></span>
                </div>
              </div>
            </td>
            <td data-label="Contact">
              <div class="admin-clients-contact">
                <span>@ <?= lex_e($client['email']) ?></span>
                <span># <?= lex_e((string) ($client['contact_number'] ?: 'No phone number')) ?></span>
              </div>
            </td>
            <td data-label="Risk Level">
              <span class="admin-clients-risk-pill <?= lex_e($riskClass) ?>"><?= lex_e(ucfirst((string) $client['risk_level'])) ?></span>
            </td>
            <td data-label="Actions">
              <form method="post" class="admin-clients-actions">
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="update_risk">
                <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                <select name="risk_level" aria-label="Risk level">
                  <option value="low"<?= ($client['risk_level'] ?? '') === 'low' ? ' selected' : '' ?>>Low</option>
                  <option value="medium"<?= ($client['risk_level'] ?? '') === 'medium' ? ' selected' : '' ?>>Medium</option>
                  <option value="high"<?= ($client['risk_level'] ?? '') === 'high' ? ' selected' : '' ?>>High</option>
                </select>
                <button class="button button-secondary admin-clients-action-button admin-clients-save-button" type="submit">Save</button>
                <button
                  class="button button-danger admin-clients-action-button"
                  type="button"
                  data-client-delete-open
                  data-client-id="<?= (int) $client['id'] ?>"
                  data-client-name="<?= lex_e((string) $client['full_name']) ?>"
                  aria-label="Delete <?= lex_e((string) $client['full_name']) ?>"
                  title="Delete client"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3.75h6a1.5 1.5 0 0 1 1.5 1.5V6H20a.75.75 0 0 1 0 1.5h-1.02l-.84 11.13A2.25 2.25 0 0 1 15.9 20.7H8.1a2.25 2.25 0 0 1-2.24-2.07L5.02 7.5H4A.75.75 0 0 1 4 6h3.5v-.75A1.5 1.5 0 0 1 9 3.75Zm1.5 2.25h3v-.75h-3V6Zm-2.13 1.5.82 10.98a.75.75 0 0 0 .75.69h5.62a.75.75 0 0 0 .75-.69l.82-10.98H8.37Z" fill="currentColor"/></svg>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?>
          <tr>
            <td colspan="4" class="admin-clients-empty">No clients matched your current filters.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= lex_admin_pagination('admin/manage_clients.php', ['q' => $search, 'risk' => $riskFilter], $totalFilteredClients, $currentPage, $perPage) ?>
  </section>

  <section class="card admin-client-card">
    <div class="card-head">
      <div>
        <h2>Risk Assessments</h2>
        <p class="muted">Latest assessment notes and scores linked to client records.</p>
      </div>
    </div>
    <div class="stack-list">
      <?php foreach ($assessments as $item): ?>
        <div class="stack-row">
          <div>
            <strong><?= lex_e($item['full_name']) ?></strong>
            <span><?= lex_e($item['notes']) ?></span>
          </div>
          <div class="stack-row-right">
            <span class="pill"><?= lex_e($item['level']) ?></span>
            <strong><?= (int) $item['score'] ?></strong>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$assessments): ?>
        <div class="stack-row">
          <div>
            <strong>No assessments recorded</strong>
            <span>No risk assessments are available yet.</span>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

<div class="modal-overlay" id="clientProfileModal" data-client-profile-modal aria-hidden="true">
  <div class="modal-card wide admin-profile-modal-card" role="dialog" aria-modal="true" aria-labelledby="clientProfileTitle">
    <div class="modal-header">
      <div>
        <h2 id="clientProfileTitle">Client Profile</h2>
        <p class="modal-note">Directory profile details</p>
      </div>
      <button class="close-button" type="button" data-client-profile-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="admin-profile-summary">
        <div class="admin-profile-avatar" data-profile-avatar-wrap aria-hidden="true"></div>
        <div>
          <h3 data-profile-name></h3>
          <span class="admin-clients-risk-pill" data-profile-risk></span>
        </div>
      </div>
      <dl class="admin-profile-details">
        <div>
          <dt>Email</dt>
          <dd data-profile-email></dd>
        </div>
        <div>
          <dt>Phone</dt>
          <dd data-profile-phone></dd>
        </div>
        <div class="full">
          <dt>Address</dt>
          <dd data-profile-address></dd>
        </div>
      </dl>
      <section class="admin-profile-password-section" aria-labelledby="clientPasswordTitle">
        <div class="admin-profile-password-head">
          <div>
            <h3 id="clientPasswordTitle">Change Password</h3>
            <p>Set a new temporary password for this client account.</p>
          </div>
        </div>
        <form method="post" class="admin-profile-password-form" data-client-password-form>
          <?= lex_csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="target_user_id" value="" data-profile-user-id-input>
          <label>
            <span>New Password</span>
            <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
          </label>
          <label>
            <span>Confirm New Password</span>
            <input type="password" name="confirm_new_password" minlength="8" autocomplete="new-password" required>
          </label>
          <p class="field-error" data-password-error aria-live="polite"></p>
          <button class="button button-primary" type="submit">Change Password</button>
        </form>
      </section>
    </div>
  </div>
</div>

<div class="modal-overlay admin-client-create-overlay" id="clientCreateModal" aria-hidden="true">
  <div class="modal-card wide admin-client-create-card" role="dialog" aria-modal="true" aria-labelledby="clientCreateTitle">
    <div class="admin-client-create-banner">
      <div>
        <h2 id="clientCreateTitle">Add New Client</h2>
        <p>Enter client information and account details</p>
      </div>
      <button class="close-button admin-client-create-close" type="button" data-client-create-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" class="form-grid admin-client-create-form">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>
          <span>Full Name</span>
          <input type="text" name="full_name" placeholder="John Smith" required>
        </label>
        <label>
          <span>Email Address</span>
          <input type="email" name="email" placeholder="john.smith@email.com" required>
        </label>
        <label>
          <span>Phone Number</span>
          <input type="text" name="contact_number" placeholder="09123456789" required>
        </label>
        <label>
          <span>Risk Level</span>
          <select name="risk_level" required>
            <option value="low" selected>Low Risk</option>
            <option value="medium">Medium Risk</option>
            <option value="high">High Risk</option>
          </select>
        </label>
        <label class="full">
          <span>Address</span>
          <input type="text" name="address" placeholder="123 Main Street, City">
        </label>
        <label class="full">
          <span>Password</span>
          <div class="password-field" data-password-toggle>
            <input type="password" name="password" minlength="10" placeholder="10+ chars with upper, lower, number, symbol" required>
            <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
          </div>
        </label>
        <div class="admin-client-create-actions">
          <button class="button button-primary admin-client-create-submit" type="submit">Add Client</button>
          <button class="button button-secondary admin-client-create-cancel" type="button" data-client-create-close>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="clientDeleteModal" data-client-delete-modal aria-hidden="true">
  <div class="modal-card wide admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="clientDeleteTitle">
    <div class="modal-header">
      <div>
        <h2 id="clientDeleteTitle">Delete Client</h2>
        <p class="modal-note">Type the client name and the word DELETE to confirm permanent deletion.</p>
      </div>
      <button class="close-button" type="button" data-client-delete-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-error">
        This will delete the client account and dependent case records.
      </div>
      <form method="post" class="form-grid admin-client-delete-form" data-client-delete-form novalidate>
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="delete_client">
        <input type="hidden" name="client_id" value="">
        <label>Client name
          <input type="text" data-client-delete-name readonly>
        </label>
        <label>Type client name + DELETE
          <input type="text" data-client-delete-confirm-text autocomplete="off" spellcheck="false" placeholder="Enter client name and DELETE">
        </label>
        <p class="field-error" data-client-delete-error aria-live="polite"></p>
        <div class="action-group">
          <button class="button button-danger" type="submit" data-client-delete-submit>Delete Client</button>
          <button class="button button-secondary" type="button" data-client-delete-close>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  const createModal = document.getElementById('clientCreateModal');
  const createOpenButton = document.querySelector('[data-client-create-open]');
  const createCloseButtons = createModal ? createModal.querySelectorAll('[data-client-create-close]') : [];
  const profileModal = document.getElementById('clientProfileModal');
  const modal = document.getElementById('clientDeleteModal');
  const filterForm = document.querySelector('.admin-clients-filters');
  const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
  let searchTimer = 0;

  if (profileModal) {
    const avatarWrap = profileModal.querySelector('[data-profile-avatar-wrap]');
    const riskPill = profileModal.querySelector('[data-profile-risk]');
    const passwordForm = profileModal.querySelector('[data-client-password-form]');
    const passwordUserIdInput = profileModal.querySelector('[data-profile-user-id-input]');
    const passwordError = profileModal.querySelector('[data-password-error]');
    const fields = {
      name: profileModal.querySelector('[data-profile-name]'),
      email: profileModal.querySelector('[data-profile-email]'),
      phone: profileModal.querySelector('[data-profile-phone]'),
      address: profileModal.querySelector('[data-profile-address]'),
    };

    const closeProfileModal = () => {
      profileModal.classList.remove('is-open');
      profileModal.setAttribute('aria-hidden', 'true');
    };

    const openProfileModal = (button) => {
      const name = button.dataset.profileName || 'Client';
      if (fields.name) fields.name.textContent = name;
      if (passwordUserIdInput) passwordUserIdInput.value = button.dataset.profileUserId || '';
      if (passwordForm) passwordForm.reset();
      if (passwordError) passwordError.textContent = '';
      if (fields.email) fields.email.textContent = button.dataset.profileEmail || 'No email provided';
      if (fields.phone) fields.phone.textContent = button.dataset.profilePhone || 'No phone number';
      if (fields.address) fields.address.textContent = button.dataset.profileAddress || 'No address provided';
      if (riskPill) {
        riskPill.textContent = button.dataset.profileRisk || 'Low';
        riskPill.className = `admin-clients-risk-pill ${button.dataset.profileRiskClass || 'is-low'}`;
      }
      if (avatarWrap) {
        const avatar = button.dataset.profileAvatar || '';
        const initials = button.dataset.profileInitials || 'CL';
        const tone = button.dataset.profileTone || 'blue';
        avatarWrap.className = `admin-profile-avatar admin-clients-avatar--${tone}`;
        avatarWrap.textContent = '';
        if (avatar !== '') {
          const image = document.createElement('img');
          image.src = avatar;
          image.alt = '';
          avatarWrap.appendChild(image);
        } else {
          avatarWrap.textContent = initials;
        }
      }
      profileModal.classList.add('is-open');
      profileModal.setAttribute('aria-hidden', 'false');
      const closeButton = profileModal.querySelector('[data-client-profile-close]');
      if (closeButton) window.setTimeout(() => closeButton.focus(), 40);
    };

    document.querySelectorAll('[data-client-profile-open]').forEach((button) => {
      button.addEventListener('click', () => openProfileModal(button));
    });

    if (passwordForm) {
      passwordForm.addEventListener('submit', (event) => {
        const newPassword = passwordForm.querySelector('input[name="new_password"]')?.value || '';
        const confirmPassword = passwordForm.querySelector('input[name="confirm_new_password"]')?.value || '';
        if (newPassword.length < 8) {
          event.preventDefault();
          if (passwordError) passwordError.textContent = 'Password must be at least 8 characters.';
          return;
        }
        if (newPassword !== confirmPassword) {
          event.preventDefault();
          if (passwordError) passwordError.textContent = 'New password and confirmation do not match.';
        }
      });
    }

    profileModal.querySelectorAll('[data-client-profile-close]').forEach((button) => {
      button.addEventListener('click', closeProfileModal);
    });

    profileModal.addEventListener('click', (event) => {
      if (event.target === profileModal) {
        closeProfileModal();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && profileModal.classList.contains('is-open')) {
        closeProfileModal();
      }
    });
  }

  if (createModal) {
    const openCreateModal = () => {
      createModal.classList.add('is-open');
      createModal.setAttribute('aria-hidden', 'false');
      const firstField = createModal.querySelector('input, select, textarea, button');
      if (firstField) {
        window.setTimeout(() => firstField.focus(), 40);
      }
    };

    const closeCreateModal = () => {
      createModal.classList.remove('is-open');
      createModal.setAttribute('aria-hidden', 'true');
    };

    if (createOpenButton) {
      createOpenButton.addEventListener('click', openCreateModal);
    }

    createCloseButtons.forEach((button) => {
      button.addEventListener('click', closeCreateModal);
    });

    createModal.addEventListener('click', (event) => {
      if (event.target === createModal) {
        closeCreateModal();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && createModal.classList.contains('is-open')) {
        closeCreateModal();
      }
    });
  }

  const normalize = (value) => value.trim().replace(/\s+/g, ' ');

  if (!modal) {
    if (searchInput && filterForm) {
      searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => filterForm.submit(), 260);
      });
    }
    return;
  }

  const form = modal.querySelector('[data-client-delete-form]');
  const nameField = modal.querySelector('[data-client-delete-name]');
  const confirmInput = modal.querySelector('[data-client-delete-confirm-text]');
  const submitButton = modal.querySelector('[data-client-delete-submit]');
  const errorBox = modal.querySelector('[data-client-delete-error]');
  const clientIdInput = form ? form.querySelector('input[name="client_id"]') : null;
  let expectedClientName = '';

  const syncState = () => {
    const entered = normalize(confirmInput?.value || '');
    const required = `${expectedClientName} DELETE`.trim();
    const matches = expectedClientName !== '' && entered === required;
    if (submitButton) submitButton.disabled = !matches;
    if (errorBox) {
      errorBox.textContent = entered !== '' && !matches ? 'Type the exact client name followed by DELETE.' : '';
    }
    return matches;
  };

  const openModal = (button) => {
    expectedClientName = button.dataset.clientName || '';
    if (clientIdInput) clientIdInput.value = button.dataset.clientId || '';
    if (nameField) nameField.value = expectedClientName;
    if (confirmInput) confirmInput.value = '';
    if (errorBox) errorBox.textContent = '';
    if (submitButton) submitButton.disabled = true;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    if (confirmInput) {
      setTimeout(() => confirmInput.focus(), 50);
    }
  };

  document.querySelectorAll('[data-client-delete-open]').forEach((button) => {
    button.addEventListener('click', () => openModal(button));
  });

  modal.querySelectorAll('[data-client-delete-close]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    });
  });

  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }
  });

  if (confirmInput) {
    confirmInput.addEventListener('input', syncState);
    confirmInput.addEventListener('keyup', syncState);
    confirmInput.addEventListener('change', syncState);
  }

  if (form) {
    form.addEventListener('submit', (event) => {
      if (!syncState()) {
        event.preventDefault();
        if (errorBox && normalize(confirmInput?.value || '') === '') {
          errorBox.textContent = 'Type the client name followed by DELETE to confirm deletion.';
        }
      }
    });
  }

  if (submitButton && form) {
    submitButton.addEventListener('click', (event) => {
      if (!syncState()) {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      form.submit();
    });
  }

  if (searchInput && filterForm) {
    searchInput.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => filterForm.submit(), 260);
    });
  }
})();
</script>

<?php lex_page_footer(); ?>
