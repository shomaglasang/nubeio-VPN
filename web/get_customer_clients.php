<?php

include_once('config.inc');
include_once('lib.inc');

$mysqli = open_db($dp_ip, $db_user, $db_password, $db_name);
$customer_id = (isset($_GET['customer_id']) && $_GET['customer_id'] != 'all') ? $_GET['customer_id'] : null;

$devices = array();
$users = array();

$vpn_clients = get_vpn_clients($customer_id);
foreach($vpn_clients as $vpn_client)
{
  $client_info = get_client_info($vpn_client['common_name'], $vpn_client['customer_id']);
  $h = array_merge($vpn_client, $client_info);
  if ($client_info['type'] == 'user')
  {
    $users[] = $h;
  }
  else
  {
    $devices[] = $h;
  }
}

echo json_encode(array('devices' => $devices, 'users' => $users));

close_db($mysqli);
exit();
?>
