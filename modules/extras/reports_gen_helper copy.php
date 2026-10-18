<?php
define('SECURE_ACCESS', true);
require_once '../../includes/core.php';

header('Content-Type: application/json');

if (!checkPermission('reports', 'view')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}
$conn = getDbConnection();
$reportType = $_GET['reportType'] ?? '';
$startDate = $_GET['startDate'] ?? '';
$endDate = $_GET['endDate'] ?? '';

try {
    $data = [];

    switch ($reportType) {
        case 'daily':
            $data = getDailyCollection($startDate, $endDate);
            break;
        case 'monthly':
            $data = getMonthlyCollection($startDate, $endDate);
            break;
        case 'payment_mode':
            $data = getPaymentModeCollection($startDate, $endDate);
            break;
        case 'pharmacy_sales':
            $data = getPharmacySales($startDate, $endDate);
            break;
        case 'pharmacy_stock':
            $data = getPharmacyStock();
            break;
        case 'pharmacy_expiry':
            $data = getPharmacyExpiry();
            break;
        case 'ipd_admissions':
            $data = getIPDAdmissions($startDate, $endDate);
            break;
        case 'ipd_occupancy':
            $data = getWardOccupancy();
            break;
        case 'ipd_collection':
            $data = getIPDCollection($startDate, $endDate);
            break;
        case 'department':
            $data = getDepartmentCollection($startDate, $endDate);
            break;
        case 'doctor':
            $data = getDoctorCollection($startDate, $endDate);
            break;
        default:
            throw new Exception('Invalid report type');
    }

    echo json_encode($data);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

function getDailyCollection($startDate, $endDate)
{
    global $conn;

    $sql = "SELECT 
                DATE(bill_date) as date,
                COUNT(*) as bill_count,
                SUM(final_amount) as amount
            FROM bills 
            WHERE bill_date BETWEEN ? AND ?
                AND payment_status != 'CANCELLED'
            GROUP BY DATE(bill_date)
            ORDER BY date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $total += $row['amount'];
    }

    return [
        'headers' => [
            'date' => 'Date',
            'bill_count' => 'Bills',
            'amount' => 'Amount',
        ],
        'rows' => $rows,
        'totals' => [
            'date' => 'Total',
            'bill_count' => array_sum(array_column($rows, 'bill_count')),
            'amount' => $total,
        ],
    ];
}

function getMonthlyCollection($startDate, $endDate)
{
    global $conn;

    $sql = "SELECT 
                DATE_FORMAT(bill_date, '%Y-%m') as month,
                COUNT(*) as bill_count,
                SUM(final_amount) as amount
            FROM bills 
            WHERE bill_date BETWEEN ? AND ?
                AND payment_status != 'CANCELLED'
            GROUP BY DATE_FORMAT(bill_date, '%Y-%m')
            ORDER BY month";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $total += $row['amount'];
    }

    return [
        'headers' => [
            'month' => 'Month',
            'bill_count' => 'Bills',
            'amount' => 'Amount',
        ],
        'rows' => $rows,
        'totals' => [
            'month' => 'Total',
            'bill_count' => array_sum(array_column($rows, 'bill_count')),
            'amount' => $total,
        ],
    ];
}

function getPaymentModeCollection($startDate, $endDate)
{
    global $conn;

    $sql = 'SELECT 
                pt.type_name as name,
                COUNT(*) as transaction_count,
                SUM(pc.amount) as amount
            FROM payment_collections pc
            JOIN payment_types pt ON pc.payment_type_id = pt.payment_type_id
            WHERE DATE(pc.payment_date) BETWEEN ? AND ?
            GROUP BY pt.payment_type_id, pt.type_name
            ORDER BY amount DESC';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $total += $row['amount'];
    }

    return [
        'headers' => [
            'name' => 'Payment Mode',
            'transaction_count' => 'Transactions',
            'amount' => 'Amount',
        ],
        'rows' => $rows,
        'totals' => [
            'name' => 'Total',
            'transaction_count' => array_sum(array_column($rows, 'transaction_count')),
            'amount' => $total,
        ],
    ];
}

function getDepartmentCollection($startDate, $endDate)
{
    global $conn;

    $sql = 'SELECT 
                d.name as name,
                COUNT(DISTINCT b.patient_id) as patient_count,
                SUM(b.final_amount) as amount
            FROM bills b
            JOIN departments d ON b.dept_id = d.dept_id
            WHERE b.bill_date BETWEEN ? AND ?
                AND b.payment_status != "CANCELLED"
            GROUP BY d.dept_id, d.name
            ORDER BY amount DESC';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $total += $row['amount'];
    }

    return [
        'headers' => [
            'name' => 'Department',
            'patient_count' => 'Patients',
            'amount' => 'Amount',
        ],
        'rows' => $rows,
        'totals' => [
            'name' => 'Total',
            'patient_count' => array_sum(array_column($rows, 'patient_count')),
            'amount' => $total,
        ],
    ];
}

function getDoctorCollection($startDate, $endDate)
{
    global $conn;

    $sql = 'SELECT 
                d.name as name,
                COUNT(DISTINCT b.patient_id) as patient_count,
                SUM(b.final_amount) as amount
            FROM bills b
            JOIN doctors d ON b.doctor_id = d.doctor_id
            WHERE b.bill_date BETWEEN ? AND ?
                AND b.payment_status != "CANCELLED"
            GROUP BY d.doctor_id, d.name
            ORDER BY amount DESC';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $total += $row['amount'];
    }

    return [
        'headers' => [
            'name' => 'Doctor',
            'patient_count' => 'Patients',
            'amount' => 'Amount',
        ],
        'rows' => $rows,
        'totals' => [
            'name' => 'Total',
            'patient_count' => array_sum(array_column($rows, 'patient_count')),
            'amount' => $total,
        ],
    ];
}

function getPharmacySales($startDate, $endDate) {
    global $conn;
    
    $sql = "SELECT 
                DATE(ps.sale_date) as date,
                COUNT(*) as transactions,
                SUM(pti.quantity) as items_sold,
                SUM(ps.final_amount) as sales_amount,
                0 as returns_amount  -- Placeholder for returns tracking
            FROM pharmacy_sales ps
            JOIN pharmacy_transaction_items pti ON ps.transaction_id = pti.transaction_id 
            WHERE ps.sale_date BETWEEN ? AND ?
            GROUP BY DATE(ps.sale_date)
            ORDER BY date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $totals = ['sales_amount' => 0, 'returns_amount' => 0, 'items_sold' => 0, 'transactions' => 0];
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $totals['sales_amount'] += $row['sales_amount'];
        $totals['returns_amount'] += $row['returns_amount'];
        $totals['items_sold'] += $row['items_sold'];
        $totals['transactions'] += $row['transactions'];
    }

    return [
        'headers' => [
            'date' => 'Date',
            'transactions' => 'Transactions',
            'items_sold' => 'Items Sold',
            'sales_amount' => 'Sales Amount',
            'returns_amount' => 'Returns Amount'
        ],
        'rows' => $rows,
        'totals' => [
            'date' => 'Total',
            'transactions' => $totals['transactions'],
            'items_sold' => $totals['items_sold'],
            'sales_amount' => $totals['sales_amount'],
            'returns_amount' => $totals['returns_amount']
        ]
    ];
}

function getPharmacyStock() {
    global $conn;
    
    $sql = "SELECT 
                pi.name,
                pi.category,
                SUM(pb.current_quantity) as stock,
                MIN(pb.expiry_date) as nearest_expiry,
                COUNT(DISTINCT pb.batch_number) as batches,
                AVG(pb.retail_price) as avg_price
            FROM pharmacy_items pi
            LEFT JOIN pharmacy_batches pb ON pi.item_id = pb.item_id
            WHERE pb.current_quantity > 0
            GROUP BY pi.item_id, pi.name, pi.category
            ORDER BY pi.category, pi.name";

    $result = $conn->query($sql);
    $rows = [];
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return [
        'headers' => [
            'name' => 'Item Name',
            'category' => 'Category',
            'stock' => 'Current Stock',
            'batches' => 'Active Batches',
            'nearest_expiry' => 'Nearest Expiry',
            'avg_price' => 'Avg. Price'
        ],
        'rows' => $rows
    ];
}

function getPharmacyExpiry() {
    global $conn;
    
    $sql = "SELECT 
                pi.name,
                pb.batch_number,
                pb.current_quantity as stock,
                pb.expiry_date,
                pb.retail_price
            FROM pharmacy_batches pb
            JOIN pharmacy_items pi ON pb.item_id = pi.item_id
            WHERE pb.current_quantity > 0 
                AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
            ORDER BY pb.expiry_date";

    $result = $conn->query($sql);
    $rows = [];
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return [
        'headers' => [
            'name' => 'Item Name',
            'batch_number' => 'Batch',
            'stock' => 'Quantity',
            'expiry_date' => 'Expiry Date',
            'retail_price' => 'Price'
        ],
        'rows' => $rows
    ];
}

function getIPDAdmissions($startDate, $endDate) {
    global $conn;
    
    $sql = "SELECT 
                DATE(ia.admission_date) as date,
                COUNT(*) as admissions,
                COUNT(CASE WHEN ia.status = 'Discharged' THEN 1 END) as discharges,
                AVG(DATEDIFF(COALESCE(ia.discharge_date, CURRENT_DATE), ia.admission_date)) as avg_stay
            FROM ipd_admissions ia
            WHERE ia.admission_date BETWEEN ? AND ?
            GROUP BY DATE(ia.admission_date)
            ORDER BY date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $totals = ['admissions' => 0, 'discharges' => 0];
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $totals['admissions'] += $row['admissions'];
        $totals['discharges'] += $row['discharges'];
    }

    return [
        'headers' => [
            'date' => 'Date',
            'admissions' => 'Admissions',
            'discharges' => 'Discharges',
            'avg_stay' => 'Avg. Stay (Days)'
        ],
        'rows' => $rows,
        'totals' => [
            'date' => 'Total',
            'admissions' => $totals['admissions'],
            'discharges' => $totals['discharges'],
            'avg_stay' => '-'
        ]
    ];
}

function getWardOccupancy() {
    global $conn;
    
    $sql = "SELECT 
                w.ward_name,
                w.total_beds,
                COUNT(ba.bed_number) as occupied_beds,
                w.charges_per_day,
                (COUNT(ba.bed_number) / w.total_beds * 100) as occupancy_rate
            FROM wards w
            LEFT JOIN bed_allocation ba ON w.id = ba.ward_id 
                AND ba.status = 'Occupied'
            GROUP BY w.id, w.ward_name, w.total_beds, w.charges_per_day
            ORDER BY w.ward_name";

    $result = $conn->query($sql);
    $rows = [];
    $totals = ['total_beds' => 0, 'occupied_beds' => 0];
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $totals['total_beds'] += $row['total_beds'];
        $totals['occupied_beds'] += $row['occupied_beds'];
    }

    return [
        'headers' => [
            'ward_name' => 'Ward',
            'total_beds' => 'Total Beds',
            'occupied_beds' => 'Occupied',
            'charges_per_day' => 'Charges/Day',
            'occupancy_rate' => 'Occupancy %'
        ],
        'rows' => $rows,
        'totals' => [
            'ward_name' => 'Total',
            'total_beds' => $totals['total_beds'],
            'occupied_beds' => $totals['occupied_beds'],
            'charges_per_day' => '-',
            'occupancy_rate' => round($totals['occupied_beds'] / $totals['total_beds'] * 100, 2)
        ]
    ];
}

function getIPDCollection($startDate, $endDate) {
    global $conn;
    
    $sql = "SELECT 
                DATE(pc.payment_date) as date,
                COUNT(DISTINCT ia.admission_id) as patients,
                SUM(pc.amount) as collection
            FROM payment_collections pc
            JOIN ipd_admissions ia ON pc.admission_id = ia.admission_id
            WHERE pc.payment_date BETWEEN ? AND ?
            GROUP BY DATE(pc.payment_date)
            ORDER BY date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $totals = ['patients' => 0, 'collection' => 0];
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $totals['patients'] += $row['patients'];
        $totals['collection'] += $row['collection'];
    }

    return [
        'headers' => [
            'date' => 'Date',
            'patients' => 'Patients',
            'collection' => 'Collection'
        ],
        'rows' => $rows,
        'totals' => [
            'date' => 'Total',
            'patients' => $totals['patients'],
            'collection' => $totals['collection']
        ]
    ];
}