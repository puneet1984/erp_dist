<?php
define('SECURE_ACCESS', true);

require_once '../../includes/core.php';
require_once '../../includes/helpers/reports_helper.php';

if (!checkPermission('admin', 'edit')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}

// Load existing settings
$settings = getReportSettings();

// Check if settings were loaded successfully
if (empty($settings)) {
    exit('Error: Unable to load report settings. Please check the error log.');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
</head>

<body class="bg-light">
<?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container py-4">
        <div class="row mb-4">
            <div class="col">
                <h2 class="border-bottom pb-2">Reports Settings</h2>
                <div id="saveStatus"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form id="reportsSettingsForm" class="needs-validation" novalidate>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Default Report Format:</label>
                                <select name="default_format" class="form-select" required>
                                    <option value="pdf" <?php echo ('pdf' === $settings['default_format']) ? 'selected' : ''; ?>>PDF</option>
                                    <option value="excel" <?php echo ('excel' === $settings['default_format']) ? 'selected' : ''; ?>>Excel</option>
                                    <option value="csv" <?php echo ('csv' === $settings['default_format']) ? 'selected' : ''; ?>>CSV</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Paper Size:</label>
                                <select name="paper_size" class="form-select" required>
                                    <option value="A4" <?php echo ('A4' === $settings['paper_size']) ? 'selected' : ''; ?>>A4</option>
                                    <option value="letter" <?php echo ('letter' === $settings['paper_size']) ? 'selected' : ''; ?>>Letter</option>
                                    <option value="legal" <?php echo ('legal' === $settings['paper_size']) ? 'selected' : ''; ?>>Legal</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Header Logo:</label>
                                <div class="logo-upload-container">
                                    <?php if (!empty($settings['header_logo'])) { ?>
                                        <img src="<?php echo htmlspecialchars($settings['header_logo']); ?>" class="img-thumbnail mb-2" style="max-height: 100px;">
                                    <?php } ?>
                                    <input type="file" name="header_logo" accept="image/*" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Footer Text:</label>
                                <textarea name="footer_text" class="form-control" rows="2"><?php echo htmlspecialchars($settings['footer_text'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Automated Reports</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check mb-3">
                                        <input type="checkbox" class="form-check-input" name="auto_daily_reports" id="autoDailyReports"
                                            <?php echo ($settings['auto_daily_reports']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="autoDailyReports">Auto-generate Daily Reports</label>
                                    </div>
                                </div>
                                <div class="col-md-6" id="timeSettings">
                                    <div class="mb-3">
                                        <label class="form-label">Generation Time:</label>
                                        <input type="time" name="daily_report_time" class="form-control"
                                            value="<?php echo htmlspecialchars($settings['daily_report_time'] ?? '23:59'); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Email Distribution</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check mb-3">
                                        <input type="checkbox" class="form-check-input" name="auto_email_reports" id="autoEmailReports"
                                            <?php echo ($settings['auto_email_reports']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="autoEmailReports">Auto-email Reports</label>
                                    </div>
                                </div>
                                <div class="col-md-6" id="emailSettings">
                                    <div class="mb-3">
                                        <label class="form-label">Recipients:</label>
                                        <input type="text" name="report_recipients" class="form-control"
                                            value="<?php echo htmlspecialchars($settings['report_recipients'] ?? ''); ?>"
                                            placeholder="email1@domain.com, email2@domain.com"
                                            pattern="^(\s*[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\s*,?\s*)*$">
                                        <div class="invalid-feedback">Please enter valid email addresses separated by commas</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="reset" class="btn btn-secondary me-2">Reset</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('reportsSettingsForm');
            const timeSettings = document.getElementById('timeSettings');
            const emailSettings = document.getElementById('emailSettings');

            // Toggle dependent fields
            form.auto_daily_reports.addEventListener('change', e => {
                timeSettings.style.display = e.target.checked ? 'block' : 'none';
            });

            form.auto_email_reports.addEventListener('change', e => {
                emailSettings.style.display = e.target.checked ? 'block' : 'none';
            });

            // Form submission
            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                if (!form.checkValidity()) {
                    e.stopPropagation();
                    form.classList.add('was-validated');
                    return;
                }

                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

                try {
                    const response = await fetch('../../includes/helpers/reports_helper.php', {
                        method: 'POST',
                        body: new FormData(form)
                    });

                    const data = await response.json();

                    if (data.status === 'success') {
                        toastr.success('Settings saved successfully');
                    } else {
                        toastr.error(data.message || 'Error saving settings');
                    }
                } catch (error) {
                    toastr.error('An unexpected error occurred');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Save Settings';
                }
            });

            // Initialize toggle states
            timeSettings.style.display = form.auto_daily_reports.checked ? 'block' : 'none';
            emailSettings.style.display = form.auto_email_reports.checked ? 'block' : 'none';
        });
    </script>
</body>

</html>