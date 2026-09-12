<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');

$pdo = lex_pdo();
$totalCases = lex_stats('SELECT COUNT(*) FROM cases');
$activeLawyers = lex_stats("SELECT COUNT(*) FROM lawyers WHERE status = 'active'");
$openCases = lex_stats("SELECT COUNT(*) FROM cases WHERE status IN ('open','ongoing')");
$riskRows = lex_recent('SELECT risk_level, COUNT(*) AS total FROM clients GROUP BY risk_level');
$failedIps = lex_recent("SELECT ip_address, COUNT(*) AS total FROM audit_logs WHERE action = 'failed_login' GROUP BY ip_address ORDER BY total DESC LIMIT 6");
$failedLoginCount = lex_stats("SELECT COUNT(*) FROM audit_logs WHERE action = 'failed_login'");
$latestFailedLogin = lex_recent("SELECT performed_at FROM audit_logs WHERE action = 'failed_login' ORDER BY performed_at DESC LIMIT 1");
$lockedAccounts = lex_stats("SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()");
$recentAudit = lex_recent('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.performed_at DESC LIMIT 6');
$recentCases = lex_recent('SELECT c.case_number, c.title, c.status, c.priority, u.full_name AS lawyer_name FROM cases c JOIN lawyers l ON l.id = c.lawyer_id JOIN users u ON u.id = l.user_id ORDER BY c.id DESC LIMIT 5');
$latestFailedLoginTime = $latestFailedLogin[0]['performed_at'] ?? null;
$riskTotal = (int) array_sum(array_column($riskRows, 'total'));
$riskChartColors = array_map(static function (array $row): string {
    return match (strtolower((string) ($row['risk_level'] ?? ''))) {
        'low' => '#2fbf71',
        'medium' => '#f0c94a',
        'high' => '#e5484d',
        default => '#7d8da8',
    };
}, $riskRows);

lex_page_header('Admin Dashboard', 'dashboard');
?>
<section class="admin-dashboard-stats" aria-label="Dashboard summary">
  <article class="admin-dashboard-stat-card">
    <div class="admin-dashboard-stat-icon tone-blue" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v12.5A1.75 1.75 0 0 1 18.25 20H5.75A1.75 1.75 0 0 1 4 18.25V5.75A1.75 1.75 0 0 1 5.75 4Zm1.5 3v2.75h9.5V7h-9.5Zm0 4.25v5.5h3.5v-5.5h-3.5Zm5 0v5.5h4.5v-5.5h-4.5Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-dashboard-stat-copy">
      <span>Total Cases</span>
      <strong><?= number_format($totalCases) ?></strong>
    </div>
  </article>
  <article class="admin-dashboard-stat-card">
    <div class="admin-dashboard-stat-icon tone-green" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M8.5 11a3.25 3.25 0 1 0-3.25-3.25A3.25 3.25 0 0 0 8.5 11Zm7 1.5A2.75 2.75 0 1 0 12.75 9.75 2.75 2.75 0 0 0 15.5 12.5ZM3.75 19.25c0-2.4 2.96-4.25 6.75-4.25s6.75 1.85 6.75 4.25a.75.75 0 0 1-1.5 0c0-1.33-2.13-2.75-5.25-2.75s-5.25 1.42-5.25 2.75a.75.75 0 0 1-1.5 0Zm12.68-3.97c2.34.28 4.07 1.56 4.07 3.22a.75.75 0 0 1-1.5 0c0-.73-.92-1.43-2.75-1.72a.75.75 0 1 1 .18-1.5Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-dashboard-stat-copy">
      <span>Active Lawyers</span>
      <strong class="tone-success"><?= number_format($activeLawyers) ?></strong>
    </div>
  </article>
  <article class="admin-dashboard-stat-card">
    <div class="admin-dashboard-stat-icon tone-green" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M5.75 4h4.12c.5 0 .98.2 1.33.56l1.09 1.11c.07.07.17.11.27.11h5.69A1.75 1.75 0 0 1 20 7.53v10.72A1.75 1.75 0 0 1 18.25 20h-12.5A1.75 1.75 0 0 1 4 18.25V5.75A1.75 1.75 0 0 1 5.75 4Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-dashboard-stat-copy">
      <span>Open Cases</span>
      <strong class="tone-success"><?= number_format($openCases) ?></strong>
    </div>
  </article>
  <article class="admin-dashboard-stat-card">
    <div class="admin-dashboard-stat-icon tone-red" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm.75 4h-1.5v6.2h1.5v-6.2Zm0 8.1h-1.5v1.7h1.5v-1.7Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-dashboard-stat-copy">
      <span>Risk Coverage</span>
      <strong class="tone-danger"><?= number_format(array_sum(array_column($riskRows, 'total'))) ?></strong>
    </div>
  </article>
</section>

<section class="content-grid two-col admin-dashboard-workbench">
  <article class="card admin-dashboard-card admin-risk-card">
    <div class="card-head">
      <div>
        <span class="admin-dashboard-kicker">Client exposure</span>
        <h2>Risk Overview</h2>
      </div>
      <span class="pill"><?= number_format($riskTotal) ?> clients</span>
    </div>
    <div class="admin-risk-layout">
      <div class="admin-risk-chart">
        <canvas id="riskChart" height="144"></canvas>
      </div>
      <div class="admin-risk-list">
        <?php foreach ($riskRows as $row): ?>
          <?php
            $riskCount = (int) $row['total'];
            $riskPercent = $riskTotal > 0 ? round(($riskCount / $riskTotal) * 100) : 0;
          ?>
          <div class="admin-risk-row">
            <div>
              <strong><?= lex_e(ucfirst((string) $row['risk_level'])) ?></strong>
              <span><?= number_format($riskCount) ?> client<?= $riskCount === 1 ? '' : 's' ?></span>
            </div>
            <em><?= $riskPercent ?>%</em>
          </div>
        <?php endforeach; ?>
        <?php if (!$riskRows): ?>
          <div class="admin-risk-row">
            <div><strong>No risk data</strong><span>Client profiles have not been classified yet.</span></div>
            <em>0%</em>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </article>
  <article class="card admin-dashboard-card admin-security-card">
    <div class="card-head">
      <div>
        <span class="admin-dashboard-kicker">Login defense</span>
        <h2>Security Activity</h2>
      </div>
      <span class="pill">Live audit</span>
    </div>
    <div class="admin-security-metrics">
      <div class="admin-security-metric">
        <span>Failed logins</span>
        <strong><?= number_format($failedLoginCount) ?></strong>
        <small>Recorded login failures</small>
      </div>
      <div class="admin-security-metric">
        <span>Locked accounts</span>
        <strong><?= number_format($lockedAccounts) ?></strong>
        <small>Currently restricted</small>
      </div>
      <div class="admin-security-metric">
        <span>Latest failure</span>
        <strong><?= lex_e($latestFailedLoginTime ? lex_message_timestamp((string) $latestFailedLoginTime) : 'None') ?></strong>
        <small>Most recent failed login</small>
      </div>
    </div>
    <div class="admin-dashboard-subhead">
      <h3>Top Failed IPs</h3>
      <span class="pill">Last 6</span>
    </div>
    <div class="admin-ip-list">
      <?php foreach ($failedIps as $row): ?>
        <div class="admin-ip-row">
          <span><?= lex_e($row['ip_address']) ?></span>
          <strong><?= number_format((int) $row['total']) ?> attempts</strong>
        </div>
      <?php endforeach; ?>
      <?php if (!$failedIps): ?><div class="admin-empty-line">No suspicious IPs recorded yet.</div><?php endif; ?>
    </div>
  </article>
</section>

<div class="admin-dashboard-compact">
<section class="content-grid two-col admin-dashboard-workbench">
  <article class="card admin-dashboard-card admin-recent-cases-card">
    <div class="card-head">
      <div>
        <span class="admin-dashboard-kicker">Case queue</span>
        <h2>Recent Cases</h2>
      </div>
    </div>
    <div class="admin-case-list">
      <?php foreach ($recentCases as $row): ?>
        <div class="admin-case-row">
          <div>
            <strong><?= lex_e($row['title']) ?></strong>
            <span><?= lex_e($row['case_number']) ?></span>
          </div>
          <div class="admin-case-meta">
            <span><?= lex_e($row['lawyer_name']) ?></span>
            <span class="pill"><?= lex_e($row['status']) ?></span>
            <em><?= lex_e($row['priority']) ?></em>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$recentCases): ?><div class="admin-empty-line">No recent cases yet.</div><?php endif; ?>
    </div>
  </article>
  <article class="card admin-dashboard-card admin-audit-feed-card">
    <div class="card-head">
      <div>
        <span class="admin-dashboard-kicker">System trail</span>
        <h2>Audit Feed</h2>
      </div>
      <a class="button button-secondary" href="audit_logs.php">View all</a>
    </div>
    <div class="admin-audit-list">
      <?php foreach ($recentAudit as $row): ?>
        <div class="admin-audit-row">
          <span class="admin-audit-action" title="<?= lex_e(lex_activity_label((string) $row['action'])) ?>"><?= lex_e(lex_activity_label((string) $row['action'])) ?></span>
          <div>
            <strong><?= lex_e($row['full_name'] ?? 'System') ?></strong>
            <span><?= lex_e($row['target_table'] . ':' . $row['target_id']) ?></span>
          </div>
          <time><?= lex_e($row['performed_at']) ?></time>
        </div>
      <?php endforeach; ?>
      <?php if (!$recentAudit): ?><div class="admin-empty-line">No audit entries yet.</div><?php endif; ?>
    </div>
  </article>
</section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('riskChart');
if (ctx) {
  const isLightTheme = document.documentElement.dataset.theme === 'light';
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_column($riskRows, 'risk_level')) ?>,
      datasets: [{
        data: <?= json_encode(array_map('intval', array_column($riskRows, 'total'))) ?>,
        backgroundColor: <?= json_encode($riskChartColors) ?>,
        borderColor: '#07162a',
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      cutout: '68%',
      plugins: {
        legend: {
          position: 'right',
          labels: { boxWidth: 10, boxHeight: 10, color: isLightTheme ? '#30415a' : '#d7e2f2', font: { size: 11, weight: '700' } }
        }
      }
    }
  });
}
</script>
<?php lex_page_footer(); ?>
