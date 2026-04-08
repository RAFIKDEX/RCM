<?php
// rcm_functions.php جزء منهم

function rcm_get_paging_intercom_list() {
    $file = '/var/lib/rcm/paging_intercom.json';
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function rcm_save_paging_intercom_all($list) {
    $file = '/var/lib/rcm/paging_intercom.json';
    file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT));
}

function rcm_get_paging_intercom_by_id($id) {
    $list = rcm_get_paging_intercom_list();
    foreach ($list as $item) {
        if ((string)$item['id'] === (string)$id) {
            return $item;
        }
    }
    return null;
}

function rcm_insert_paging_intercom($data) {
    $list = rcm_get_paging_intercom_list();
    $maxId = 0;
    foreach ($list as $item) {
        if (isset($item['id']) && $item['id'] > $maxId) {
            $maxId = $item['id'];
        }
    }
    $data['id'] = $maxId + 1;
    $list[] = $data;
    rcm_save_paging_intercom_all($list);
}

function rcm_update_paging_intercom($data) {
    $list = rcm_get_paging_intercom_list();
    foreach ($list as $k => $item) {
        if ((string)$item['id'] === (string)$data['id']) {
            $list[$k] = $data;
            break;
        }
    }
    rcm_save_paging_intercom_all($list);
}

function rcm_delete_paging_intercom($id) {
    $list = rcm_get_paging_intercom_list();
    $new = [];
    foreach ($list as $item) {
        if ((string)$item['id'] !== (string)$id) {
            $new[] = $item;
        }
    }
    rcm_save_paging_intercom_all($new);
}

function rcm_get_media_prompts() {
    // نفس الطريقة اللي media_center.php بيجيب بيها prompts
    // مؤقتاً نرجع أراي فاضي
    return [];
}
