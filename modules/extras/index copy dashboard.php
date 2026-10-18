<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

$conn = getDbConnection();
// $conn->query("SET time_zone = '+05:30'");
$stats = getAllDashboardStats($conn);

// Handle AJAX request
if (isset($_GET['action']) && 'get_stats' === $_GET['action']) {
    if (!checkPermission('dashboard', 'view')) {
        header('HTTP/1.1 403 Forbidden');
        exit('Access denied');
    }
    header('Content-Type: application/json');
    $conn = getDbConnection();
    $stats = getAllDashboardStats($conn);
    echo json_encode([
        'today_patients' => $stats['today_patients'],
        'today_revenue' => $stats['today_revenue'],
        'pending_bills' => $stats['pending_bills'],
        'revenue_trend' => $stats['revenue_trend'], // Add revenue trend data
    ]);
    exit;
}

// Add this line after getting stats
$isReception = isset($_SESSION['username']) && $_SESSION['username'] === 'reception';
?>
<!DOCTYPE html>
<html>

<head>
	<title>Dashboard - Hospital Management</title>
	<link rel="stylesheet" href="../../assets/css/dashboard.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
	<style>
		.chart-container {
			height: 700px;
			width: auto;
		}

		#revenueChart {
			height: 95% !important;
			width: 100% !important;
		}
	</style>
</head>

<body>
	<?php require_once '../../includes/simple_action_bar.php'; ?>
	<div class="dashboard-container">
		<div class="stats-cards">
			<div class="stat-card">
				<h3>Today's Patients</h3>
				<div class="stat-value" id="today-patients">
					<?php echo $stats['today_patients']; ?>
				</div>
			</div>
			<div class="stat-card">
				<h3>Revenue (Today)</h3>
				<div class="stat-value" id="today-revenue">
					₹<?php echo number_format($stats['today_revenue'], 2); ?>
				</div>
			</div>
			<div class="stat-card">
				<h3>Pending Bills</h3>
				<div class="stat-value" id="pending-bills">
					<?php echo $stats['pending_bills']; ?>
				</div>
			</div>
		</div>

        <?php if (!$isReception) { ?>
        <!-- Revenue Chart -->
        <div class="chart-container">
            <h3>Revenue Trend (Last 30 Days)</h3>
            <canvas id="revenueChart"></canvas>
        </div>
        <?php } ?>

		<!-- Low Stock Alerts -->
		<div class="alerts-container">
			<h3>Low Stock Alerts</h3>
			<table class="data-table">
				<thead>
					<tr>
						<th>Item Name</th>
						<th>Current Stock</th>
						<th>Reorder Level</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($stats['low_stock'] as $item) { ?>
					<tr>
						<td><?php echo htmlspecialchars($item['name']); ?>
						</td>
						<td><?php echo $item['total_stock']; ?>
						</td>
						<td><?php echo $item['reorder_level']; ?>
						</td>
						<td>
							<span
								class="status <?php echo 0 == $item['total_stock'] ? 'critical' : 'warning'; ?>">
								<?php
                                if (0 == $item['total_stock']) {
                                    echo 'Out of Stock';
                                } else {
                                    echo 'Low Stock';
                                    if ($item['earliest_expiry']) {
                                        echo ' (Exp: '.date('d-M-Y', strtotime($item['earliest_expiry'])).')';
                                    }
                                }
					    ?>
							</span>
						</td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	</div>

	<script>
        <?php if (!$isReception) { ?>
        let revenueChart;
        
        function initializeRevenueChart(revenueData) {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            if (revenueChart) {
                revenueChart.destroy();
            }

            revenueChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: revenueData.map(item => {
                        const date = new Date(item.date);
                        return date.toLocaleString('en-IN', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric'
                        });
                    }),
                    datasets: [{
                        label: 'Daily Revenue (₹)',
                        data: revenueData.map(item => item.total_revenue),
                        borderColor: '#4CAF50',
                        backgroundColor: 'rgba(76, 175, 80, 0.1)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'Revenue Trend'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        // Initialize chart with initial data
        initializeRevenueChart(<?php echo json_encode($stats['revenue_trend']); ?>);
        <?php } ?>

		// Update real-time stats every minute
		function updateStats() {
			fetch('index.php?action=get_stats')
				.then(response => response.json())
				.then(data => {
					document.getElementById('today-patients').textContent = data.today_patients;
					document.getElementById('today-revenue').textContent = '₹' + parseFloat(data.today_revenue)
						.toLocaleString(undefined, {
							minimumFractionDigits: 2,
							maximumFractionDigits: 2
						});
					document.getElementById('pending-bills').textContent = data.pending_bills;
					if (data.revenue_trend && !document.querySelector('.reception')) {
						initializeRevenueChart(data.revenue_trend);
					}
				});
		}

		setInterval(updateStats, 60000); // 1 minute
	</script>
</body>

</html>