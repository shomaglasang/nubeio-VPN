<?php

/* DB settings */
$db_ip = 'localhost';
$db_name = 'vpn';
$db_user = 'vpnuser';
$db_password = 'vpnuser*pw123';


function get_vpn_clients()
{
  $vpn_servers = get_vpn_servers();
  $vpn_clients = array();

  foreach ($vpn_servers as $server)
  {
    $vpn_status_log = $server['vpn_server_status_log'];
    if (empty($vpn_status_log))
    {
      continue;
    }

    $fd = fopen($vpn_status_log, "r");
    if (!$fd)
    {
      echo "Failed to open file\n";
      continue;
    }

    $found = false;
    while (($line = fgets($fd)) !== false)
    {
      $a = explode(",", $line);
      if (empty($a))
      {
        continue;
      }

      if (!$found)
      {
        if ($a[0] == 'Virtual Address')
        {
          $found = true;
        }

        continue;
      }

      if (count($a) != 4)
      {
        break;
      }

      $h = array("ip" => $a[0],
        "common_name" => $a[1],
        "public_ip" => explode(":", $a[2])[0],
        "customer_id" => $server['id']
      );

      $key = $a[1] . '-' . $a[0];
      $vpn_clients[$key] = $h;
    }

    fclose($fd);
  }

  return($vpn_clients);
}

function get_vpn_servers()
{
  global $mysqli;
  $servers = array();

  $sql = "select * from customers where deactivated_at is null order by common_name";
  if ($result = $mysqli->query($sql))
  {
    while ($row = $result->fetch_assoc())
    {
      $servers[] = array(
        'id' => $row['id'],
        'name' => $row['name'],
        'common_name' => $row['common_name'],
        'description' => $row['description'],
        'vpn_server_dir' => $row['vpn_server_dir'],
        'vpn_server_config' => $row['vpn_server_config'],
        'vpn_server_status_log' => $row['vpn_server_status_log']
      );
    }
  }

  return($servers);
}

function get_client_info($device_cn, $customer_id)
{
  global $mysqli;
  $info = array();

  $sql = "select cl.*,c.common_name as customer_cn,c.name as customer_name,c.ca_dir " .
    "from clients cl join customers c on c.id=cl.customer_id where cl.common_name='" . $device_cn . "' and c.id=" . $customer_id;
  $result = $mysqli->query($sql);
  if ($result && ($row = $result->fetch_assoc()))
  {
    $info['type'] = $row['type'];
    $info['name'] = $row['name'];
    $info['description'] = $row['description'];
    $info['status'] = $row['status'];
    $info['customer_name'] = $row['customer_name'];
    $info['cert_expiry'] = $row['expiry'];
  }

  return($info);
}

$mysqli = new mysqli($dp_ip, $db_user, $db_password, $db_name);
if ($mysqli->connect_errno) {
  echo "Error connecting to DB:" . $mysqli->connect_error;
  exit;
}

echo '<html>
<title>VPN Monitor</title>
<link type="text/css" rel="stylesheet" href="/assets/css/style.css" />
<body>
<div class="title">Online VPN Clients</div><br/>';

$vpn_clients = get_vpn_clients();

$users = array();

if (!empty($vpn_clients))
{
  echo '<div>Devices</div>';
  echo '<table class="online">
    <tr><td>#&nbsp;&nbsp;&nbsp;</td><td>VPN Client Name</td><td>VPN IP</td><td>Expiry</td><td>Public IP</td><td>Description</td><td>Customer Name</td></tr>';

  $i = 1;
  foreach($vpn_clients as $vpn_client)
  {
    $client_info = get_client_info($vpn_client['common_name'], $vpn_client['customer_id']);
    if ($client_info['type'] == 'user')
    {
      $users[] = array_merge($vpn_client, $client_info);
      continue;
    }

    echo "<tr><td>$i</td><td>" . $vpn_client['common_name'] .
      "</td><td>" . $vpn_client['ip'] .
      "</td><td>" . $client_info['cert_expiry'] . 
      "</td><td>" . $vpn_client['public_ip'] .
      "</td><td>" . $client_info['description'] .
      "</td><td>" . $client_info['customer_name'] .
      "</td></tr>";
    $i++;
  }

  echo '</table>';
}

if (!empty($users))
{
  echo '<br/><br/><div>Users</div>';
  echo '<table class="online">
    <tr><td>#&nbsp;&nbsp;&nbsp;</td><td>VPN Client Name</td><td>VPN IP</td><td>Expiry</td><td>Public IP</td><td>Description</td><td>Customer Name</td></tr>';

  $i = 1;
  foreach($users as $user)
  {
    echo "<tr><td>$i</td><td>" . $user['common_name'] .
      "</td><td>" . $user['ip'] .
      "</td><td>" . $user['cert_expiry'] .
      "</td><td>" . $user['public_ip'] .
      "</td><td>" . $user['description'] .
      "</td><td>" . $user['customer_name'] .
      "</td></tr>";
    $i++;
  }

  echo '</table>';
}

echo '</body>
</html>';
?>
