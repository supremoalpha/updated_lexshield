<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();

function lex_lawyer_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'LX';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'LX';
}

function lex_lawyer_initial_tone(string $name): string
{
    $tones = ['blue', 'green', 'purple', 'orange', 'cyan', 'indigo', 'gold'];
    $index = abs(crc32(strtolower(trim($name)))) % count($tones);
    return $tones[$index];
}

function lex_lawyer_summary(array $lawyer): string
{
    $summary = trim((string) ($lawyer['background'] ?: $lawyer['bio'] ?: ''));
    if ($summary === '') {
        return 'Profile details not added yet';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($summary, 0, 70, '...');
    }

    return strlen($summary) > 70 ? substr($summary, 0, 67) . '...' : $summary;
}

function lex_lawyer_specializations(): array
{
    return [
        'Criminal Law',
        'Corporate Law',
        'Cyber Law',
        'Family Law',
        'Civil Litigation',
        'Labor Law',
        'Property Law',
        'Tax Law',
        'Immigration Law',
        'Intellectual Property Law',
    ];
}

function lex_lawyer_status_label(string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'busy' => 'Busy',
        'suspended' => 'Suspended',
        default => ucfirst($status),
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('admin/manage_lawyers.php');
        lex_flash_set('error', 'Invalid CSRF token.');
        header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
        exit;
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add') {
                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $email = lex_sanitize_email($_POST['email'] ?? '');
                $barNumber = lex_sanitize_text($_POST['bar_number'] ?? '');
                $specialization = lex_sanitize_text($_POST['specialization'] ?? '');
                $bio = lex_sanitize_multiline_text($_POST['bio'] ?? '');
                $background = lex_sanitize_multiline_text($_POST['background'] ?? '');
                $password = (string) ($_POST['password'] ?? '');

                if ($fullName === '' || $email === '' || $barNumber === '' || $specialization === '') {
                    throw new RuntimeException('Please complete all required fields.');
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Please enter a valid email address.');
                }

                if (!in_array($specialization, lex_lawyer_specializations(), true)) {
                    throw new RuntimeException('Choose a valid specialization.');
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

                $stmt = $pdo->prepare('SELECT id FROM lawyers WHERE bar_number = :bar_number LIMIT 1');
                $stmt->execute(['bar_number' => $barNumber]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException('A lawyer already exists with that bar number.');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, created_at) VALUES (:full_name, :email, :password_hash, "lawyer", 1, NOW())');
                $stmt->execute(['full_name' => $fullName, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_BCRYPT)]);
                $userId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO lawyers (user_id, bar_number, specialization, status, bio, background) VALUES (:user_id, :bar_number, :specialization, "active", :bio, :background)');
                $stmt->execute(['user_id' => $userId, 'bar_number' => $barNumber, 'specialization' => $specialization, 'bio' => $bio, 'background' => $background]);
                lex_audit('add_lawyer', 'lawyers', (string) $userId);
                $pdo->commit();
                lex_flash_set('success', 'Lawyer added successfully.');
                header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                exit;
            } elseif ($action === 'toggle') {
                $id = lex_sanitize_int($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT l.id, l.status, l.user_id FROM lawyers l WHERE l.id = :id');
                $stmt->execute(['id' => $id]);
                $lawyer = $stmt->fetch();
                if ($lawyer) {
                    $newStatus = $lawyer['status'] === 'active' ? 'suspended' : 'active';
                    $pdo->prepare('UPDATE lawyers SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
                    $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :uid')->execute(['active' => $newStatus === 'active' ? 1 : 0, 'uid' => $lawyer['user_id']]);
                    lex_audit('toggle_lawyer', 'lawyers', (string) $id);
                    lex_audit('admin_changes_user_status', 'users', (string) $lawyer['user_id']);
                    lex_flash_set('success', 'Lawyer status updated.');
                    header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                    exit;
                }
            } elseif ($action === 'change_password') {
                $targetUserId = lex_sanitize_int($_POST['target_user_id'] ?? 0);
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_new_password'] ?? '');

                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException('New password and confirmation do not match.');
                }

                $emailSent = lex_admin_change_user_password($targetUserId, 'lawyer', $newPassword);
                lex_flash_set(
                    $emailSent ? 'success' : 'warning',
                    $emailSent
                        ? 'Lawyer password changed successfully and the new temporary password was emailed.'
                        : 'Lawyer password changed successfully, but the email notification could not be sent.'
                );
                header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                exit;
            } elseif ($action === 'set_status') {
                $id = lex_sanitize_int($_POST['id'] ?? 0);
                $newStatus = strtolower(lex_sanitize_text($_POST['status'] ?? ''));
                if (!in_array($newStatus, ['active', 'busy', 'suspended'], true)) {
                    throw new RuntimeException('Invalid lawyer status.');
                }

                $stmt = $pdo->prepare('SELECT user_id FROM lawyers WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $userId = (int) ($stmt->fetchColumn() ?: 0);
                if ($userId) {
                    $pdo->prepare('UPDATE lawyers SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
                    $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :uid')->execute([
                        'active' => $newStatus === 'suspended' ? 0 : 1,
                        'uid' => $userId,
                    ]);
                    lex_audit('set_lawyer_status', 'lawyers', (string) $id);
                    lex_audit('admin_changes_user_status', 'users', (string) $userId);
                    lex_flash_set('success', 'Lawyer status updated.');
                    header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                    exit;
                }
            } elseif ($action === 'delete') {
                $id = lex_sanitize_int($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT user_id FROM lawyers WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $userId = (int) ($stmt->fetchColumn() ?: 0);
                if ($userId) {
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
                    lex_audit('delete_lawyer', 'lawyers', (string) $id);
                    lex_flash_set('success', 'Lawyer removed.');
                    header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            lex_flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to complete the requested action.');
            header('Location: ' . lex_app_url('admin/manage_lawyers.php'));
            exit;
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'busy', 'suspended'], true)) {
    $statusFilter = 'all';
}

$lawyerWhere = [];
$lawyerParams = [];

if ($search !== '') {
    $searchValue = '%' . $search . '%';
    $lawyerWhere[] = '(u.full_name LIKE :search_name OR u.email LIKE :search_email OR l.bar_number LIKE :search_bar OR l.specialization LIKE :search_specialization)';
    $lawyerParams['search_name'] = $searchValue;
    $lawyerParams['search_email'] = $searchValue;
    $lawyerParams['search_bar'] = $searchValue;
    $lawyerParams['search_specialization'] = $searchValue;
}

if ($statusFilter !== 'all') {
    $lawyerWhere[] = 'l.status = :status';
    $lawyerParams['status'] = $statusFilter;
}

$perPage = 10;
$lawyerFrom = ' FROM lawyers l JOIN users u ON u.id = l.user_id';
$lawyerFilterSql = '';
if ($lawyerWhere) {
    $lawyerFilterSql = ' WHERE ' . implode(' AND ', $lawyerWhere);
}
$totalFilteredLawyers = lex_stats('SELECT COUNT(*)' . $lawyerFrom . $lawyerFilterSql, $lawyerParams);
$totalLawyerPages = max(1, (int) ceil($totalFilteredLawyers / $perPage));
$currentPage = min(max(1, lex_sanitize_int($_GET['page'] ?? 1)), $totalLawyerPages);
$offset = ($currentPage - 1) * $perPage;

$lawyerQuery = 'SELECT l.*, u.full_name, u.email, u.id AS user_id, u.avatar_stored_name' . $lawyerFrom . $lawyerFilterSql . ' ORDER BY l.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$lawyers = lex_recent($lawyerQuery, $lawyerParams);
$totalLawyers = lex_stats('SELECT COUNT(*) FROM lawyers');
$activeLawyers = lex_stats("SELECT COUNT(*) FROM lawyers WHERE status = 'active'");
$busyLawyers = lex_stats("SELECT COUNT(*) FROM lawyers WHERE status = 'busy'");
$suspendedLawyers = lex_stats("SELECT COUNT(*) FROM lawyers WHERE status = 'suspended'");
$specializationOptions = lex_lawyer_specializations();

lex_page_header('Manage Lawyers', 'lawyers');
?>
  <section class="admin-lawyers-stats" aria-label="Lawyer summary">
    <article class="admin-lawyers-stat-card">
      <div class="admin-lawyers-stat-icon tone-blue" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M8.5 11a3.25 3.25 0 1 0-3.25-3.25A3.25 3.25 0 0 0 8.5 11Zm7 1.5A2.75 2.75 0 1 0 12.75 9.75 2.75 2.75 0 0 0 15.5 12.5ZM3.75 19.25c0-2.4 2.96-4.25 6.75-4.25s6.75 1.85 6.75 4.25a.75.75 0 0 1-1.5 0c0-1.33-2.13-2.75-5.25-2.75s-5.25 1.42-5.25 2.75a.75.75 0 0 1-1.5 0Zm12.68-3.97c2.34.28 4.07 1.56 4.07 3.22a.75.75 0 0 1-1.5 0c0-.73-.92-1.43-2.75-1.72a.75.75 0 1 1 .18-1.5Z" fill="currentColor"/></svg>
      </div>
      <div class="admin-lawyers-stat-copy">
        <span>Total Lawyers</span>
        <strong><?= number_format($totalLawyers) ?></strong>
      </div>
    </article>
    <article class="admin-lawyers-stat-card">
      <div class="admin-lawyers-stat-icon tone-green" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm4.18 6.38-5.05 5.05-2.31-2.31-1.06 1.06 3.37 3.37 6.11-6.1-1.06-1.07Z" fill="currentColor"/></svg>
      </div>
      <div class="admin-lawyers-stat-copy">
        <span>Active</span>
        <strong class="tone-success"><?= number_format($activeLawyers) ?></strong>
      </div>
    </article>
    <article class="admin-lawyers-stat-card">
      <div class="admin-lawyers-stat-icon tone-amber" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M8 3.5h8v3.25a4 4 0 0 1-1.17 2.83L12.41 12l2.42 2.42A4 4 0 0 1 16 17.25v3.25H8v-3.25a4 4 0 0 1 1.17-2.83L11.59 12 9.17 9.58A4 4 0 0 1 8 6.75V3.5Zm1.5 1.5v1.75c0 .66.26 1.3.73 1.77L12 10.29l1.77-1.77c.47-.47.73-1.1.73-1.77V5h-5Zm2.5 8.71-1.77 1.77a2.5 2.5 0 0 0-.73 1.77V19h5v-1.75c0-.66-.26-1.3-.73-1.77L12 13.71Z" fill="currentColor"/></svg>
      </div>
      <div class="admin-lawyers-stat-copy">
        <span>Busy</span>
        <strong class="tone-warning"><?= number_format($busyLawyers) ?></strong>
      </div>
    </article>
    <article class="admin-lawyers-stat-card">
      <div class="admin-lawyers-stat-icon tone-red" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm3.18 5.51L12 11.94 8.82 8.76 7.76 9.82 10.94 13l-3.18 3.18 1.06 1.06L12 14.06l3.18 3.18 1.06-1.06L13.06 13l3.18-3.18-1.06-1.06Z" fill="currentColor"/></svg>
      </div>
      <div class="admin-lawyers-stat-copy">
        <span>Suspended</span>
        <strong class="tone-danger"><?= number_format($suspendedLawyers) ?></strong>
      </div>
    </article>
  </section>

  <section class="card admin-lawyers-directory-card">
    <div class="admin-lawyers-directory-head">
      <div>
        <h2>Lawyers Directory</h2>
        <p>Manage and view all lawyers in your organization</p>
      </div>
      <form method="get" class="admin-lawyers-filters">
        <label class="admin-lawyers-search">
          <span class="sr-only">Search lawyers</span>
          <span class="admin-lawyers-field-icon" aria-hidden="true"></span>
          <input type="search" name="q" value="<?= lex_e($search) ?>" placeholder="Search lawyers...">
        </label>
        <label class="admin-lawyers-status-filter">
          <span class="sr-only">Filter by status</span>
          <select name="status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All Status</option>
            <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="busy"<?= $statusFilter === 'busy' ? ' selected' : '' ?>>Busy</option>
            <option value="suspended"<?= $statusFilter === 'suspended' ? ' selected' : '' ?>>Suspended</option>
          </select>
        </label>
        <button class="button admin-lawyers-add-button admin-lawyers-toolbar-button" type="button" data-lawyer-modal-open>
          <span aria-hidden="true">+</span>
          <span>Add Lawyer</span>
        </button>
      </form>
    </div>

    <div class="table-wrap">
      <table class="data-table admin-lawyers-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Bar</th>
            <th>Specialization</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lawyers as $lawyer): ?>
          <?php
            $summary = lex_lawyer_summary($lawyer);
            $lawyerStatus = (string) ($lawyer['status'] ?? 'active');
            $avatarUrl = lex_profile_avatar_url((string) ($lawyer['avatar_stored_name'] ?? ''));
            $initials = lex_lawyer_initials((string) $lawyer['full_name']);
            $tone = lex_lawyer_initial_tone((string) $lawyer['full_name']);
          ?>
          <tr>
            <td data-label="Name">
              <div class="admin-lawyers-person">
                <?php if ($avatarUrl !== ''): ?>
                  <img class="admin-lawyers-avatar" src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e((string) $lawyer['full_name']) ?>">
                <?php else: ?>
                  <span class="admin-lawyers-avatar admin-lawyers-avatar--<?= lex_e($tone) ?>" aria-hidden="true"><?= lex_e($initials) ?></span>
                <?php endif; ?>
                <div>
                  <button
                    class="admin-directory-name-button"
                    type="button"
                    data-lawyer-profile-open
                    data-profile-name="<?= lex_e((string) $lawyer['full_name']) ?>"
                    data-profile-user-id="<?= (int) $lawyer['user_id'] ?>"
                    data-profile-email="<?= lex_e((string) $lawyer['email']) ?>"
                    data-profile-phone="<?= lex_e((string) ($lawyer['contact_number'] ?: 'No phone number')) ?>"
                    data-profile-bar="<?= lex_e((string) ($lawyer['bar_number'] ?: 'No bar number')) ?>"
                    data-profile-specialization="<?= lex_e((string) ($lawyer['specialization'] ?: 'No specialization')) ?>"
                    data-profile-status="<?= lex_e(lex_lawyer_status_label($lawyerStatus)) ?>"
                    data-profile-status-class="is-<?= lex_e($lawyerStatus) ?>"
                    data-profile-background="<?= lex_e((string) ($lawyer['background'] ?: 'No background added yet')) ?>"
                    data-profile-bio="<?= lex_e((string) ($lawyer['bio'] ?: 'No bio added yet')) ?>"
                    data-profile-avatar="<?= lex_e($avatarUrl) ?>"
                    data-profile-initials="<?= lex_e($initials) ?>"
                    data-profile-tone="<?= lex_e($tone) ?>"
                    aria-label="View profile for <?= lex_e((string) $lawyer['full_name']) ?>"
                  ><?= lex_e($lawyer['full_name']) ?></button>
                  <span><?= lex_e($summary) ?></span>
                </div>
              </div>
            </td>
            <td data-label="Email">
              <div class="admin-lawyers-inline-meta">
                <span class="admin-lawyers-meta-icon" aria-hidden="true">@</span>
                <span><?= lex_e($lawyer['email']) ?></span>
              </div>
            </td>
            <td data-label="Bar">
              <div class="admin-lawyers-inline-meta">
                <span class="admin-lawyers-meta-icon" aria-hidden="true">#</span>
                <span><?= lex_e($lawyer['bar_number']) ?></span>
              </div>
            </td>
            <td data-label="Specialization">
              <span class="admin-lawyers-specialization-pill"><?= lex_e($lawyer['specialization']) ?></span>
            </td>
            <td data-label="Status">
              <span class="admin-lawyers-status-pill is-<?= lex_e($lawyerStatus) ?>">
                <span class="admin-lawyers-status-dot" aria-hidden="true"></span>
                <?= lex_e(lex_lawyer_status_label($lawyerStatus)) ?>
              </span>
            </td>
            <td data-label="Actions">
              <div class="admin-lawyers-actions">
                <form method="post" class="admin-lawyers-status-form">
                  <?= lex_csrf_field() ?>
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="id" value="<?= (int) $lawyer['id'] ?>">
                  <label>
                    <span class="sr-only">Set lawyer status</span>
                    <select name="status" onchange="this.form.submit()">
                      <option value="active"<?= $lawyerStatus === 'active' ? ' selected' : '' ?>>Active</option>
                      <option value="busy"<?= $lawyerStatus === 'busy' ? ' selected' : '' ?>>Busy</option>
                      <option value="suspended"<?= $lawyerStatus === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                    </select>
                  </label>
                </form>
                <form method="post" onsubmit="return confirm('Remove this lawyer?');">
                  <?= lex_csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $lawyer['id'] ?>">
                  <button class="button button-danger admin-lawyers-action-button" type="submit" aria-label="Delete <?= lex_e((string) $lawyer['full_name']) ?>" title="Delete lawyer">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3.75h6a1.5 1.5 0 0 1 1.5 1.5V6H20a.75.75 0 0 1 0 1.5h-1.02l-.84 11.13A2.25 2.25 0 0 1 15.9 20.7H8.1a2.25 2.25 0 0 1-2.24-2.07L5.02 7.5H4A.75.75 0 0 1 4 6h3.5v-.75A1.5 1.5 0 0 1 9 3.75Zm1.5 2.25h3v-.75h-3V6Zm-2.13 1.5.82 10.98a.75.75 0 0 0 .75.69h5.62a.75.75 0 0 0 .75-.69l.82-10.98H8.37Z" fill="currentColor"/></svg>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lawyers): ?>
          <tr>
            <td colspan="6" class="admin-lawyers-empty">No lawyers matched your current filters.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= lex_admin_pagination('admin/manage_lawyers.php', ['q' => $search, 'status' => $statusFilter], $totalFilteredLawyers, $currentPage, $perPage) ?>
  </section>

<div class="modal-overlay" id="lawyerProfileModal" data-lawyer-profile-modal aria-hidden="true">
  <div class="modal-card wide admin-profile-modal-card" role="dialog" aria-modal="true" aria-labelledby="lawyerProfileTitle">
    <div class="modal-header">
      <div>
        <h2 id="lawyerProfileTitle">Lawyer Profile</h2>
        <p class="modal-note">Directory profile details</p>
      </div>
      <button class="close-button" type="button" data-lawyer-profile-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="admin-profile-summary">
        <div class="admin-profile-avatar" data-profile-avatar-wrap aria-hidden="true"></div>
        <div>
          <h3 data-profile-name></h3>
          <span class="admin-lawyers-status-pill" data-profile-status></span>
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
        <div>
          <dt>Bar Number</dt>
          <dd data-profile-bar></dd>
        </div>
        <div>
          <dt>Specialization</dt>
          <dd data-profile-specialization></dd>
        </div>
        <div class="full">
          <dt>Background</dt>
          <dd data-profile-background></dd>
        </div>
        <div class="full">
          <dt>Bio</dt>
          <dd data-profile-bio></dd>
        </div>
      </dl>
      <section class="admin-profile-password-section" aria-labelledby="lawyerPasswordTitle">
        <div class="admin-profile-password-head">
          <div>
            <h3 id="lawyerPasswordTitle">Change Password</h3>
            <p>Set a new temporary password for this lawyer account.</p>
          </div>
        </div>
        <form method="post" class="admin-profile-password-form" data-lawyer-password-form>
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

<div class="modal-overlay admin-lawyer-modal-overlay" id="lawyerCreateModal" aria-hidden="true">
  <div class="modal-card wide admin-lawyer-modal-card" role="dialog" aria-modal="true" aria-labelledby="lawyerCreateTitle">
    <div class="admin-lawyer-modal-banner">
      <div>
        <h2 id="lawyerCreateTitle">Add New Lawyer</h2>
        <p>Fill in the details to add a new lawyer to your team</p>
      </div>
      <button class="close-button admin-lawyer-modal-close" type="button" data-lawyer-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" class="form-grid admin-lawyer-form admin-lawyer-modal-form">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>
          <span>Full Name</span>
          <input type="text" name="full_name" placeholder="John Smith" required>
        </label>
        <label>
          <span>Email Address</span>
          <input type="email" name="email" placeholder="john.smith@gmail.com" required>
        </label>
        <label>
          <span>Bar Number</span>
          <input type="text" name="bar_number" placeholder="ROLL NO. 1234" required>
        </label>
        <label>
          <span>Specialization</span>
          <select name="specialization" required>
            <option value="" selected disabled>Select specialization</option>
            <?php foreach ($specializationOptions as $option): ?>
              <option value="<?= lex_e($option) ?>"><?= lex_e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="full">
          <span>Background</span>
          <textarea name="background" rows="4" placeholder="Short background, experience, or practice history..."></textarea>
        </label>
        <label>
          <span>Password</span>
          <div class="password-field" data-password-toggle>
            <input type="password" name="password" minlength="10" placeholder="10+ chars with upper, lower, number, symbol" required>
            <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
          </div>
        </label>
        <label class="full">
          <span>Bio</span>
          <textarea name="bio" rows="4" placeholder="Professional biography, achievements, and qualifications..."></textarea>
        </label>
        <div class="admin-lawyer-modal-actions">
          <button class="button button-secondary admin-lawyer-cancel" type="button" data-lawyer-modal-close>Cancel</button>
          <button class="button button-primary admin-lawyer-submit" type="submit">Add Lawyer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('lawyerCreateModal');
  const profileModal = document.getElementById('lawyerProfileModal');

  const openButton = modal ? document.querySelector('[data-lawyer-modal-open]') : null;
  const closeButtons = modal ? modal.querySelectorAll('[data-lawyer-modal-close]') : [];
  const filterForm = document.querySelector('.admin-lawyers-filters');
  const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
  let searchTimer = 0;

  if (profileModal) {
    const avatarWrap = profileModal.querySelector('[data-profile-avatar-wrap]');
    const statusPill = profileModal.querySelector('[data-profile-status]');
    const passwordForm = profileModal.querySelector('[data-lawyer-password-form]');
    const passwordUserIdInput = profileModal.querySelector('[data-profile-user-id-input]');
    const passwordError = profileModal.querySelector('[data-password-error]');
    const fields = {
      name: profileModal.querySelector('[data-profile-name]'),
      email: profileModal.querySelector('[data-profile-email]'),
      phone: profileModal.querySelector('[data-profile-phone]'),
      bar: profileModal.querySelector('[data-profile-bar]'),
      specialization: profileModal.querySelector('[data-profile-specialization]'),
      background: profileModal.querySelector('[data-profile-background]'),
      bio: profileModal.querySelector('[data-profile-bio]'),
    };

    const closeProfileModal = () => {
      profileModal.classList.remove('is-open');
      profileModal.setAttribute('aria-hidden', 'true');
    };

    const openProfileModal = (button) => {
      if (fields.name) fields.name.textContent = button.dataset.profileName || 'Lawyer';
      if (passwordUserIdInput) passwordUserIdInput.value = button.dataset.profileUserId || '';
      if (passwordForm) passwordForm.reset();
      if (passwordError) passwordError.textContent = '';
      if (fields.email) fields.email.textContent = button.dataset.profileEmail || 'No email provided';
      if (fields.phone) fields.phone.textContent = button.dataset.profilePhone || 'No phone number';
      if (fields.bar) fields.bar.textContent = button.dataset.profileBar || 'No bar number';
      if (fields.specialization) fields.specialization.textContent = button.dataset.profileSpecialization || 'No specialization';
      if (fields.background) fields.background.textContent = button.dataset.profileBackground || 'No background added yet';
      if (fields.bio) fields.bio.textContent = button.dataset.profileBio || 'No bio added yet';
      if (statusPill) {
        statusPill.textContent = button.dataset.profileStatus || 'Active';
        statusPill.className = `admin-lawyers-status-pill ${button.dataset.profileStatusClass || 'is-active'}`;
      }
      if (avatarWrap) {
        const avatar = button.dataset.profileAvatar || '';
        const initials = button.dataset.profileInitials || 'LX';
        const tone = button.dataset.profileTone || 'blue';
        avatarWrap.className = `admin-profile-avatar admin-lawyers-avatar--${tone}`;
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
      const closeButton = profileModal.querySelector('[data-lawyer-profile-close]');
      if (closeButton) window.setTimeout(() => closeButton.focus(), 40);
    };

    document.querySelectorAll('[data-lawyer-profile-open]').forEach((button) => {
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

    profileModal.querySelectorAll('[data-lawyer-profile-close]').forEach((button) => {
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

  if (!modal) return;

  const openModal = () => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    const firstField = modal.querySelector('input, textarea, select, button');
    if (firstField) {
      window.setTimeout(() => firstField.focus(), 40);
    }
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  };

  if (openButton) {
    openButton.addEventListener('click', openModal);
  }

  closeButtons.forEach((button) => {
    button.addEventListener('click', closeModal);
  });

  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeModal();
    }
  });

  if (searchInput && filterForm) {
    searchInput.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => filterForm.submit(), 260);
    });
  }
})();
</script>
<?php lex_page_footer(); ?>
