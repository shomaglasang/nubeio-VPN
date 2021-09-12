#!/usr/bin/php
<?php

/*
 * Generate OpenVPN configration file for device and user.
 * - generate certificate (private and public)
 * - generate ovpn client configuration file
 * - allocate VPN IP address and generate client specific configuratiion file
 */

define('RET_OK',            0);
define('RET_ERR',           1);

define('LOG_STDOUT',        0x1);
define('LOG_SYSTEM_SYSLOG', 0x2);
define('LOG_ALL',           0x3);


/*
 * Log message.
 */
function do_log($msg, $out = LOG_SYSLOG, $level = LOG_INFO)
{
  switch ($out)
  {
    case LOG_STDOUT:
      echo "$msg\n";
      break;

    case LOG_SYSLOG:
      syslog($level, $msg);
      break;

    default:
      echo "$msg\n";
      syslog($level, $msg);
      break;
  }
}


/*
 * Print usage.
 */
function print_usage($code)
{
  global $prog_name;

  echo "Usage: $prog_name <-n client_name><-c customer_name>[-hvOVSD][-t type][-D client_description]\n" .
       "  Where:\n" .
       "    h = Show help\n" .
       "    v = Verbose\n" .
       "    O = Overwrite existing configs/cert/key\n" .
       "    V = Do not create client VPN config\n" .
       "    S = Do not create client specific config\n" .
       "    D = Do not add client to DB\n" .
       "    t = Type (device|user). Default to device\n" .
       "    n = Client common name\n" .
       "    D = Client description\n" .
       "    c = Customer name\n" .
       "    d = Device VPN IP pool network. Default to 10.8.1\n" .
       "    u = User VPN IP pool network. Defalt 10.8.200\n" .
       "\n";

  exit($code);
}


/*
 * Parse arguments.
 */
function parse_args()
{
  global $argv;
  global $options;

  if (count($argv) == 1)
  {
    return(0);
  }

  $opt_str = "hvOVSDt:c:n:D:a:p:d:u:";

  $opts = getopt($opt_str);
  if (!$opts)
  {
    echo "Invalid argument.\n";
    print_usage(1);
  }

  if (isset($opts['h']))
  {
    print_usage(0);
  }

  if (isset($opts['v']))
  {
    $options['verbose'] = true;
  }

  if (isset($opts['t']))
  { 
    $options['type'] = ($opts['t'] == 'user') ? 'user' : 'device';
  }

  if (isset($opts['c']))
  { 
    $options['customer_name'] = $opts['c'];
  }

  if (isset($opts['n']))
  { 
    $options['client_name'] = $opts['n'];
  }

  if (isset($opts['D']))
  { 
    $options['client_description'] = $opts['D'];
  }

  if (isset($opts['O']))
  {
    $options['overwrite'] = true;
  }

  if (isset($opts['a']))
  { 
    $options['server_ip'] = $opts['a'];
  }

  if (isset($opts['p']))
  { 
    $options['server_port'] = $opts['p'];
  }

  if (isset($opts['V']))
  { 
    $options['create_vpn_config'] = false;
  }

  if (isset($opts['S']))
  { 
    $options['create_client_config'] = false;
  }

  if (isset($opts['D']))
  { 
    $options['add_client_to_db'] = false;
  }

  if (isset($opts['d']))
  { 
    $options['device_vpn_ip_pool'] = $opts['d'];
  }

  if (isset($opts['u']))
  { 
    $options['user_vpn_ip_pool'] = $opts['u'];
  }
}


/*
 * Get customer info.
 */
function get_customer_info($customer_name)
{
  global $options;

  $ovpn_dir = '/etc/openvpn-' . $customer_name;
  if (!is_dir($ovpn_dir))
  {
    if ($options['verbose'])
    {
      do_log("- VPN directory ($ovpn_dir) not found!", LOG_ALL);
    }

    return(null);
  }

  $ca_dir = '/etc/easy-rsa-' . $customer_name;
  if (!is_dir($ca_dir))
  {
    if ($options['verbose'])
    {
      do_log("- CA directory ($ovpn_dir) not found!", LOG_ALL);
    }
    
    return(null);
  }

  $cb = array(
    'ovpn_dir' => $ovpn_dir,
    'ca_dir' => $ca_dir
  );

  return($cb);
}


/*
 * Check if client already exists.
 */
function is_client_existing($name, $ccb)
{
  $cert_path = $ccb['ca_dir'] . '/pki/issued/' . $name . '.crt';

  return(file_exists($cert_path));
}


/*
 * Cleanup client data.
 * - private and public keys, cert req, etc
 */
function cleanup_client_data($client_name, $ccb)
{
  $priv_key_path = $ccb['ca_dir'] . '/pki/private/' . $client_name . '.key';
  if (file_exists($priv_key_path))
  {
    unlink($priv_key_path);
  }

  $cert_path = $ccb['ca_dir'] . '/pki/issued/' . $client_name . '.crt';
  if (file_exists($cert_path))
  {
    unlink($cert_path);
  }
}


/*
 * Create client VPN config.
 */
function create_vpn_config($options, $ccb)
{
  do_log("- Generating client VPN configuration.", LOG_ALL);

  /* generete certificate request */
  $cmd = 'cd ' . $ccb['ca_dir'] . ' && echo ' . $options['client_name'] . ' | ./easyrsa gen-req ' . $options['client_name'] . ' nopass > /dev/null 2>&1';
  $output = null;
  $ret_val = null;
  $ret = exec($cmd, $output, $ret_val);
  if ($ret_val != 0)
  {
    do_log("- Error generating certificate request!", LOG_ALL);
    return(RET_ERR);
  }

  /* sign certificate request */
  $cmd = 'cd ' . $ccb['ca_dir'] . ' && echo yes  | ./easyrsa sign-req client ' . $options['client_name'] . ' > /dev/null 2>&1';
  $output = null;
  $ret_val = null;
  $ret = exec($cmd, $output, $ret_val);
  if ($ret_val != 0)
  {
    do_log("- Error signing certificate request!", LOG_ALL);
    return(RET_ERR);
  }

  /* create openvpn client configuration file */
  $ovpn_path = $ccb['ovpn_dir'] . '/client/configs/' . $options['type'] . 's/' . $options['client_name'] . '.ovpn';
  $fd = fopen($ovpn_path, "w");
  if (!$fd)
  {
    do_log("- Error creating OVPN file ($ovpn_path)", LOG_ALL);
    return(RET_ERR);
  }

  fwrite($fd, "dev tun\n");
  fwrite($fd, "persist-tun\n");
  fwrite($fd, "persist-key\n");
  fwrite($fd, "cipher AES-256-CBC\n");
  fwrite($fd, "auth SHA256\n");
  fwrite($fd, "tls-client\n");
  fwrite($fd, "client\n");
  fwrite($fd, "resolv-retry infinite\n");
  fwrite($fd, "remote " . $options['server_ip'] . ' ' . $options['server_port'] . " udp4\n");
  fwrite($fd, "remote-cert-tls server\n");
  fwrite($fd, "pull\n");
  fwrite($fd, "<ca>\n");

  $ca_str = file_get_contents($ccb['ovpn_dir'] . '/ca.crt');
  fwrite($fd, $ca_str);

  fwrite($fd, "</ca>\n");
  fwrite($fd, "<cert>\n");

  unset($cert_str);
  $ret_val = null;
  $cmd = '/usr/bin/openssl x509 -in ' . $ccb['ca_dir'] . '/pki/issued/' . $options['client_name'] . '.crt';
  exec($cmd, $cert_str, $ret_val);
  fwrite($fd, implode("\n", $cert_str));

  fwrite($fd, "\n</cert>\n");
  fwrite($fd, "<key>\n");

  $key_str = file_get_contents($ccb['ca_dir'] . '/pki/private/' . $options['client_name'] . '.key');
  fwrite($fd, $key_str);

  fwrite($fd, "</key>\n");
  fwrite($fd, "key-direction 1\n");
  fwrite($fd, "<tls-auth>\n");

  $ta_str = file_get_contents($ccb['ovpn_dir'] . '/ta.key');
  fwrite($fd, $ta_str);

  fwrite($fd, "</tls-auth>\n");
  fclose($fd);

  do_log("-- Generated: $ovpn_path", LOG_ALL);

  return(RET_OK);
}


/*
 * Create client specific config.
 */
function create_client_config($options, $ccb)
{
  global $vpn_ip_last_octet;

  do_log("- Generating client specific configuration.", LOG_ALL);
  if ($options['type'] == 'device')
  {
    $start_ip_pool = $options['device_vpn_ip_pool'];
    $end_ip_pool = $options['user_vpn_ip_pool'];
  }
  else
  {
    $start_ip_pool = $options['user_vpn_ip_pool'];
    $a = explode('.', $start_ip_pool);
    $end_ip_pool = sprintf("%s.%s.255", $a[0], $a[1]);
  }

  do_log("-- Start VPN IP Pool: $start_ip_pool", LOG_ALL);
  do_log("-- End VPN IP Pool: $end_ip_pool", LOG_ALL);

  $clients = get_customer_clients($options['customer_name'], $options['type']);
  do_log("-- Clients found: " . count($clients), LOG_ALL);

  /* find last assigned IP */
  $last_ip = null;
  foreach($clients as $client)
  {
    if ($client['common_name'] == $options['client_name'])
    {
      continue;
    }

    if ($options['verbose'])
    {
      do_log("--- Checking IP of client $client[common_name]", LOG_ALL);
    }

    $ccf_path = sprintf("%s/ccd/%s", $ccb['ovpn_dir'], $client['common_name']);
    if (!file_exists($ccf_path))
    {
      continue;
    }

    $ip = get_ip_from_ccf($ccf_path);
    if (empty($ip))
    {
      continue;
    }

    if ($options['verbose'])
    {
      do_log("--- IP found: [$ip]", LOG_ALL);
    }

    if (empty($last_ip))
    {
      $last_ip = $ip;
    }
    else
    {
      $last_ip = get_higher_ip($last_ip, $ip);
    }
  }

  do_log("-- Last assigned IP: [$last_ip]", LOG_ALL);

  /* get next available */
  $next_ip = get_next_ip($start_ip_pool, $end_ip_pool, $last_ip);
  do_log("-- Next Available IP: [$next_ip]", LOG_ALL);

  $ccf_path = sprintf("%s/ccd/%s", $ccb['ovpn_dir'], $options['client_name']);
  $fd = fopen($ccf_path, "w");
  if ($fd === false)
  {
    do_log("-- Error creating client config file", LOG_ALL);
    return(RET_ERR);
  }

  $a = explode('.', $next_ip);
  $line = sprintf("ifconfig-push %s %s.%s.%s.%d", $next_ip,
    $a[0], $a[1], $a[2], intval($a[3]) + 1);
  fwrite($fd, "$line");

  fclose($fd);

  return(RET_OK);
}


/*
 * Get next available IP.
 */
function get_next_ip($start_ip_pool, $end_ip_pool, $last_ip)
{
  global $vpn_ip_last_octet;
  $next_ip = null;

  $start_octets = explode('.', $start_ip_pool);
  $end_octets = explode('.', $end_ip_pool);

  if (empty($last_ip))
  {
    $next_ip = sprintf("%s.%s.%s.%d", $start_octets[0], $start_octets[1],
      $start_octets[2], $vpn_ip_last_octet[0]);
  }
  else
  {
    $last_octets = explode('.', $last_ip);
    if ($last_ip >= 253)
    {
      $next_ip = sprintf("%s.%s.%d.%d", $last_octets[0], $last_octets[1],
        intval($last_octets[2]) + 1, $vpn_ip_last_octet[0]);
    }
    else
    {
      $next_ip = sprintf("%s.%s.%s.%d", $last_octets[0], $last_octets[1],
        $last_octets[2], intval($last_octets[3]) + 4);
    }
  }

  return($next_ip);
}


/*
 *  Return higher IP from the two given IP address.
 */
function get_higher_ip($ip_a, $ip_b)
{
  $a = explode('.', $ip_a);
  $b = explode('.', $ip_b);

  if (($b[0] > $a[0]) ||
    ($b[0] == $a[0] && $b[1] > $a[1]) ||
    ($b[0] == $a[0] && $b[1] == $a[1] && $b[2] > $a[2]) ||
    ($b[0] == $a[0] && $b[1] == $a[1] && $b[2] == $a[2] && $b[3] > $a[3]))
  {
    return($ip_b);
  }
  else
  {
    return($ip_a);
  }
}


/*
 * Get IP address from client specif config file.
 */
function get_ip_from_ccf($ccf_path)
{
  $ip = null;

  $fd = fopen($ccf_path, "r");
  if ($fd === false)
  {
    return($ip);
  }

  while ($line = fgets($fd))
  {
    $a = explode(" ", $line);
    $kw = trim($a[0]);
    if ($kw !== 'ifconfig-push')
    {
      continue;
    }

    $ip = trim($a[1]);
    break;
  }

  return($ip);
}


/*
 * Get customer clients.
 */
function get_customer_clients($customer_name, $type)
{
  global $mysqli;
  $clients = array();

  $cb = get_customer_db_cb($customer_name);
  if (empty($cb))
  {
    do_log("- Customer not found in DB.", LOG_ALL);
    return(RET_ERR);
  }

  $sql = sprintf("select cl.* from clients cl join customers c on c.id=cl.customer_id where c.common_name='%s' and cl.type='%s' and cl.deactivated_at is null order by cl.common_name",
    $customer_name, $type);
  if ($result = $mysqli->query($sql))
  {
    while ($row = $result->fetch_assoc())
    {
      $clients[] = array(
        'id' => $row['id'],
	'name' => $row['name'],
        'common_name' => $row['common_name']
      );
    }
  }

  return($clients);
}


/*
 * Generate client config.
 */
function generate_client_config($options, $ccb)
{
  if ($options['overwrite'])
  {
    cleanup_client_data($options['client_name'], $ccb);
  }

  if ($options['create_vpn_config'])
  {
    $ret = create_vpn_config($options, $ccb);
    if ($ret != RET_OK)
    {
      return(RET_ERR);
    }
  }

  /* create client specific configuration file */
  if ($options['create_client_config'])
  {
    $ret = create_client_config($options, $ccb);
    if ($ret != RET_OK)
    {
      return(RET_ERR);
    }
  }

  /* create client entry in database */
  if ($options['add_client_to_db'])
  {
    delete_client_from_db($options['client_name'], $options['customer_name']);
    $ret = add_client_to_db($options['client_name'], $options['client_description'], $options['customer_name'], $options['type']);
    if ($ret != RET_OK)
    {
      return(RET_ERR);
    }
  }

  return(RET_OK);
}


/*
 * Validate client name.
 * - Allowed: alphanumeric, underscore, dash
 */
function is_valid_client_name($name)
{
  return(preg_match('/^[a-zA-Z0-9_\-]+$/', $name));
}


/*
 * Get customer db entry.
 */
function get_customer_db_cb($customer_name)
{
  global $mysqli;
  $cb = array();

  $sql = "select * from customers where common_name='" . $customer_name . "' and deactivated_at is null";
  $result = $mysqli->query($sql);
  if ($result && ($row = $result->fetch_assoc()))
  {
    $cb['id'] = $row['id'];
    $cb['name'] = $row['name'];
    $cb['common_name'] = $row['common_name'];
    $cb['description'] = $row['description'];
  }
 
  return($cb);
}


/*
 * Add client to customer in database.
 */
function add_client_to_db($client_name, $client_desc, $customer_name, $type)
{
  global $mysqli;
  do_log("- Adding client/customer mapping to database.", LOG_ALL);

  $cb = get_customer_db_cb($customer_name);
  if (empty($cb))
  {
    do_log("- Customer not found in DB.", LOG_ALL);
    return(RET_ERR);
  }

  $sql = sprintf("insert into clients(customer_id,name,description,common_name,type,created_at,updated_at) values(%d, '%s', '%s', '%s', '%s', now(), now())",
    $cb['id'], $client_name, $client_desc, $client_name, $type);
  $result = $mysqli->query($sql);
  if ($result === false)
  {
    do_log("- Error adding client mapping!", LOG_ALL);
    return(RET_ERR);
  }

  do_log("-- Client($type) '$client_name' added under Customer '$customer_name'.", LOG_ALL);

  return(RET_OK);
}


/*
 * Delete client from customer in database.
 */
function delete_client_from_db($client_name, $customer_name)
{
  global $mysqli;

  $cb = get_customer_db_cb($customer_name);
  if (empty($cb))
  {
    do_log("- Customer not found in DB.", LOG_ALL);
    return(RET_ERR);
  }

  $sql = sprintf("delete from clients where customer_id=%d and common_name='%s'", $cb['id'], $client_name);
  $result = $mysqli->query($sql);
  if ($result === false)
  {
    do_log("- Error deleting client mapping!", LOG_ALL);
    return(RET_ERR);
  }

  return(RET_OK);
}


/* system */
date_default_timezone_set("UTC");

/* globals */
$prog_name = $argv[0];
$options = array(
  'verbose' => false,
  'dryrun' => false,
  'type' => 'device',
  'customer_name' => null,
  'client_name' => null,
  'client_description' => null,
  'overwrite' => false,
  'create_vpn_config' => true,
  'create_client_config' => true,
  'add_client_to_db' => true,
  'server_ip' => '178.128.106.232',
  'server_port' => '1194',
  'db_ip' => 'localhost',
  'db_name' => 'vpn',
  'db_user' => 'vpnuser',
  'db_password' => 'vpnuser*pw123',
  'device_vpn_ip_pool' => '10.8.1',
  'user_vpn_ip_pool' => '10.8.200'
);

/* VPN IP last octets */
$vpn_ip_last_octet = array(
  1, 5, 9, 13, 17,
  21, 25, 29, 33, 37,
  41, 45, 49, 53, 57,
  61, 65, 69, 73, 77,
  81, 85, 89, 93, 97,
  101, 105, 109, 113, 117,
  121, 125, 129, 133, 137,
  141, 145, 149, 153, 157,
  161, 165, 169, 173, 177,
  181, 185, 189, 193, 197,
  201, 205, 209, 213, 217,
  221, 225, 229, 233, 237,
  241, 245, 249, 253
);

do_log("Starting ${prog_name}", LOG_ALL);

/* parse args */
parse_args();

/* connect to database */
$mysqli = new mysqli($options['db_ip'], $options['db_user'], $options['db_password'], $options['db_name']);
if ($mysqli->connect_errno) {
  do_log("- Error connecting to DB:" . $mysqli->connect_error, LOG_ALL);
  exit(1);
}

/* check if customer exists */
if (empty($options['customer_name']) ||
  ($customer_cb = get_customer_info($options['customer_name'])) == null)
{
  do_log("- Unknown/missing customer name!", LOG_ALL);
  exit(1);
}

/* check if client name is valid */
if (empty($options['client_name']) || !is_valid_client_name($options['client_name']))
{
  do_log("- Missing/invalid client name!", LOG_ALL);
  exit(1);
}

/* check if client already exists */
if (is_client_existing($options['client_name'], $customer_cb) && !$options['overwrite'] && $options['create_vpn_config'])
{
  do_log("- Client name existing/used.", LOG_ALL);
  exit(1);
}

$ret = generate_client_config($options, $customer_cb);

do_log("Done.", LOG_ALL);
exit(0);

?>
