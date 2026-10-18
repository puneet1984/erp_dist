<?php
if (!defined('SECURE_ACCESS')) {
    die('Direct access not permitted');
}
require_once '../../includes/core.php';

function printBillFooter($includeButtons = true)
{
    $settings = getBillSettings();
    ?>
<div style="clear:both;"></div>

<div style="text-align: center; margin-top: 40px;">
	<!-- Use footer text from settings -->
	<p><?php echo htmlspecialchars($settings['footer_text'] ?? 'Thank you for your purchase!'); ?>
	</p>

	<!-- Terms and Conditions -->
	<div style="margin: 20px 0; text-align: left; font-size: 10px;">
		<?php
            $terms = explode("\n", $settings['terms_conditions'] ?? '');
    foreach ($terms as $term) {
        if (trim($term)) {
            echo "<p>" . htmlspecialchars(trim($term)) . "</p>";
        }
    }
    ?>
	</div>

	<p style="font-size: 10px; color: #666;">
		This is a computer generated bill, no signature required.
	</p>
</div>

<?php if ($includeButtons): ?>
<div class="no-print" style="text-align: center;">
	<button class="print-btn" onclick="window.print()">Print Bill</button>
	<button class="print-btn" style="background-color: #f44336;" onclick="window.close()">Close</button>
</div>
<?php endif; ?>

<script>
	// Auto-print when page loads
	window.onload = function() {
		if (confirm('Print bill now?')) {
			window.print();
		}
	};
</script>
</body>

</html>
<?php
}
?>