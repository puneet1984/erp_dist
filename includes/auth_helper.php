<?php

require_once 'helpers.php';

// ===== Core Path Functions =====
function getModulePath()
{
    // Simply return the base modules path without any dynamic calculation
    // This ensures we always get a correct, absolute path from the webroot
    return '/erp/modules/';
}

// ===== Session Management Functions =====
function shouldSkipSessionTimeoutForAdmin() //bypass admin session timeout
{
    return isset($_SESSION['role'])
        && ('admin' === $_SESSION['role'] || 1 === $_SESSION['role'] || 'admin' === ($_SESSION['role_name'] ?? null));
}

function checkSessionTimeout()
{
    if (shouldSkipSessionTimeoutForAdmin()) {
        // Keep activity updated for audit/tracking, but never expire admin session.
        $_SESSION['last_activity'] = time();

        return true;
    }

    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();

        return true;
    }

    $inactive = time() - $_SESSION['last_activity'];

    if ($inactive >= SESSION_TIMEOUT) {
        // Session expired
        session_destroy();

        return false;
    }

    // Update last activity time for valid session
    $_SESSION['last_activity'] = time();

    return true;
}

function redirectToLogin($params = '')
{
    $params = $params ? "?{$params}" : '';
    header('Location: '.getModulePath().'auth/login.php'.$params);
    exit;
}

// ===== Core Permission Functions =====
function isAdmin()
{
    // Add debug line
    error_log('Checking isAdmin - Role value: '.$_SESSION['role'].', Role type: '.gettype($_SESSION['role']));

    // Compare with both string and integer values since role might be stored as either
    return isset($_SESSION['role']) && ('admin' === $_SESSION['role'] || 1 === $_SESSION['role'] || 'admin' === $_SESSION['role_name']);
}

function isDoctor()
{
    return isset($_SESSION['role']) && ('doctor' === $_SESSION['role'] || 'doctor' === $_SESSION['role_name']);
}

function hasRoleName($roleName)
{
    return isset($_SESSION['role_name']) && 0 === strcasecmp((string) $_SESSION['role_name'], $roleName);
}

function isReceptionist()
{
    return hasRoleName('receptionist');
}

function isManager()
{
    return hasRoleName('manager');
}

function isAccounts()
{
    return hasRoleName('accounts');
}

function checkPermission($module, $action)
{
    // Add debug line
    error_log("Checking permission for module: {$module}, action: {$action}");

    // Check admin first
    if (isAdmin()) {
        error_log('User is admin - granting permission');

        return true;
    }

    // For non-admin users, check specific permissions
    if (isset($_SESSION['permissions'][$module])
        && in_array($action, $_SESSION['permissions'][$module])) {
        return true;
    }

    error_log("Permission denied for module: {$module}, action: {$action}");

    return false;
}

function requirePermission($module, $action)
{
    if (!checkSessionTimeout() || !checkPermission($module, $action)) {
        if (!checkSessionTimeout()) {
            redirectToLogin('timeout=1');
        }
        header('Location: '.getModulePath().'auth/unauthorized.php');
        exit;
    }
}

// ===== Module-Specific Permission Functions =====
function canAccessSupplierReturns()
{
    return checkPermission('supplier_returns', 'view') || isAdmin();
}

function canCreateSupplierReturn()
{
    return canAccessSupplierReturns() && (checkPermission('supplier_returns', 'create') || isAdmin());
}

function canDeleteSupplierReturn()
{
    return canAccessSupplierReturns() && (checkPermission('supplier_returns', 'delete') || isAdmin());
}

function canAccessBillBatchCorrections()
{
    return checkPermission('bill_batch_corrections', 'view') || isAdmin();
}

function canEditBillBatchCorrections()
{
    return canAccessBillBatchCorrections() && (checkPermission('bill_batch_corrections', 'edit') || isAdmin());
}

function canCreateFreeIssueAdjustment()
{
    return isAdmin() || checkPermission('inventory', 'add') || checkPermission('inventory', 'edit');
}

function canCreateControlledAdjustment()
{
    return isAdmin() || checkPermission('inventory', 'edit');
}

function canAccessInventoryAdjustmentForm()
{
    return isAdmin()
        || checkPermission('inventory', 'add')
        || checkPermission('inventory', 'edit')
        || checkPermission('inventory', 'delete');
}

function canCreateAdjustmentType($type)
{
    return canAccessInventoryAdjustmentForm();
}

function canRevertAdjustmentType($type)
{
    return isAdmin() || checkPermission('inventory', 'delete');
}

function canListBills()
{
    return checkPermission('bills', 'list') || checkPermission('bills', 'view');
}

// ===== Print Permission Functions =====
function canPrint($module)
{
    return checkPermission($module, 'print') || isAdmin();
}

function canPrintBill()
{
    return canPrint('bills');
}
