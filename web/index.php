<?php

/* DB settings */
$db_ip = 'localhost';
$db_name = 'vpn';
$db_user = 'vpnuser';
$db_password = 'vpnuser*pw123';


function get_vpn_clients()
{
  $vpn_clients = array();

  $fd = fopen('/var/log/openvpn/openvpn-status.log', "r");
  if ($fd)
  {
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
        "public_ip" => explode(":", $a[2])[0]
      );

      $key = $a[1] . '-' . $a[0];
      $vpn_clients[$key] = $h;
    }

    fclose($fd);
  }
  else
  {
    echo "Failed to open file\n";
  }

  return($vpn_clients);
}

function get_client_info($device_cn)
{
  global $mysqli;
  $info = array();

  $sql = "select d.*,c.common_name as customer_cn,c.name as customer_name " .
    "from devices d join customers c on c.id=d.customer_id where d.common_name='" . $device_cn . "'";
  $result = $mysqli->query($sql);
  if ($result && ($row = $result->fetch_assoc()))
  {
    $info['type'] = 'device';
    $info['name'] = $row['name'];
    $info['description'] = $row['description'];
    $info['status'] = $row['status'];
    $info['customer_name'] = $row['customer_cn'];
  }

  if (empty($info))
  {
    $sql = "select * from customers where common_name='" . $device_cn . "'";
    $result = $mysqli->query($sql);
    if ($result && ($row = $result->fetch_assoc()))
    { 
      $info['type'] = 'customer';
      $info['name'] = $row['name'];
      $info['common_name'] = $row['common_name'];
      $info['description'] = $row['description'];
      $info['status'] = $row['status'];
    }
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
ksort($vpn_clients);

$customers = array();

if (!empty($vpn_clients))
{
  echo '<div>Devices</div>';
  echo '<table class="online">
    <tr><td>#&nbsp;&nbsp;&nbsp;</td><td>Device Name</td><td>VPN IP</td><td>Public IP</td><td>Customer Name</td></tr>';

  $i = 1;
  foreach($vpn_clients as $vpn_client)
  {
    $client_info = get_client_info($vpn_client['common_name']);
    if ($client_info['type'] == 'customer')
    {
      $customers[] = array_merge($vpn_client, $client_info);
      continue;
    }

    echo "<tr><td>$i</td><td>" . $vpn_client['common_name'] .
      "</td><td>" . $vpn_client['ip'] .
      "</td><td>" . $vpn_client['public_ip'] .
      "</td><td>" . $client_info['customer_name'] .
      "</td></tr>";
    $i++;
  }

  echo '</table>';
}

if (!empty($customers))
{
  echo '<br/><br/><div>Customers</div>';
  echo '<table class="online">
    <tr><td>#&nbsp;&nbsp;&nbsp;</td><td>Customer Name</td><td>VPN IP</td><td>Public IP</td></tr>';

  $i = 1;
  foreach($customers as $customer)
  {
    echo "<tr><td>$i</td><td>" . $customer['common_name'] .
      "</td><td>" . $customer['ip'] .
      "</td><td>" . $customer['public_ip'] .
      "</td></tr>";
    $i++;
  }

  echo '</table>';
}

echo '</body>
</html>';
?>
