<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

if (!checkPermission('reports', 'view')) {
    header('Location: ../auth/unauthorized.php');
    exit;
}


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (!checkSessionTimeout()) {
    // Use the correct relative path for redirectToLogin
    header('Location: ../auth/login.php?timeout=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - ERP</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Add ChartJS datalabels plugin -->
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.3.0/dist/exceljs.min.js"></script>
</head>
<body>
    <?php require_once '../../includes/simple_action_bar.php'; ?>

    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h1 class="h3 mb-4">Financial Reports</h1>

                <div class="card">
                    <div class="card-body">
                        <form id="reportForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="reportType" class="form-label">Report Type:</label>
                                    <select id="reportType" name="reportType" class="form-select" required>
                                        <option value="daily">Daily Collection</option>
                                        <option value="monthly">Monthly Collection</option>
                                        <option value="payment_mode">Payment Mode Collection</option>
                                        <option value="pharmacy_sales">Pharmacy Sales</option>
                                        <option value="pharmacy_stock">Pharmacy Stock</option>
                                        <option value="pharmacy_expiry">Pharmacy Expiry</option>
                                        <option value="ipd_admissions">IPD Admissions</option>
                                        <option value="ipd_occupancy">IPD Occupancy</option>
                                        <option value="ipd_collection">IPD Collection</option>
                                        <option value="department">Department Collection</option>
                                        <option value="doctor">Doctor Collection</option>
                                        <option value="demographics">Patient Demographics</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label for="startDate" class="form-label">From:</label>
                                    <input type="date" id="startDate" name="startDate" class="form-control" required>
                                </div>

                                <div class="col-md-4">
                                    <label for="endDate" class="form-label">To:</label>
                                    <input type="date" id="endDate" name="endDate" class="form-control" required>
                                </div>
                            </div>

                            <div class="date-presets mt-3">
                                <button type="button" class="btn btn-outline-secondary btn-sm yesterday">Yesterday</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm this-week">This Week</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm current-month">This Month</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-days="7">Last 7 Days</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-days="30">Last 30 Days</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-days="90">Last 3 Months</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-days="180">Last 6 Months</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm last-month">Last Month</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm current-year">This Year</button>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                                <button type="button" id="exportExcel" class="btn btn-success">Export to Excel</button>
                                <button type="button" id="exportPDF" class="btn btn-danger">Export to PDF</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-body">
                        <div class="chart-container mb-4">
                            <canvas id="reportChart"></canvas>
                        </div>
                        <div class="table-responsive">
                            <table id="reportTable" class="table table-striped table-bordered">
                                <thead class="table-light">
                                </thead>
                                <tbody>
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/reports.js"></script>
</body>
</html>