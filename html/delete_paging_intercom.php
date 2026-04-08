<?php
// delete_paging_intercom.php

require_once 'auth.php';
require_once 'rcm_config.php';
require_once 'rcm_functions.php';

if (!isset($_GET['id'])) {
    header('Location: paging_intercom.php');
    exit;
}

$id = $_GET['id'];

rcm_delete_paging_intercom($id);

// بعد الحذف نولّد الكونفيج من جديد
exec('/usr/sbin/rcm_gen_paging_intercom.sh > /dev/null 2>&1 &');

header('Location: paging_intercom.php');
exit;
