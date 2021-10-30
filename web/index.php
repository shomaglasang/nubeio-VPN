<?php

include_once('config.inc');
include_once('lib.inc');


$mysqli = open_db($dp_ip, $db_user, $db_password, $db_name);

echo '<html>
<title>VPN Monitor</title>
<link type="text/css" rel="stylesheet" href="/assets/css/style.css" />
<link type="text/css" rel="stylesheet" href="/assets/css/bootstrap.min.css" />
<script type="text/javascript" src="/assets/js/jquery-2.2.0.min.js"></script>
<body>
<div class="page_title">Online VPN Clients</div>';

$servers = get_vpn_servers();
echo '<div class="customer_filter">';
$sel_html = '<select name="customer_id" id="customer_id" class="custom-select blue-text">';
$sel_html .= '<option selected value="all">All</option>';
foreach($servers as $server)
{
  $sel_html .= '<option value="' . $server['id'] . '">' . $server['name'] . '</option>';
}

$sel_html .= '</select>';
echo $sel_html;
echo '</div>';
echo '<div class="table_title">Devices</div>';
echo '<div id="client_list"><table id="client_devices" class="online">
  <tr class="table_headers"><td>#&nbsp;&nbsp;&nbsp;</td><td>VPN Client Name</td><td>VPN IP</td><td>Expiry</td><td>Public IP</td><td>Description</td><td>Customer Name</td></tr>';
echo '</table></div>';

echo '<br/><br/><div class="table_title">Users</div>';
echo '<table id="client_users" class="online">
  <tr class="table_headers"><td>#&nbsp;&nbsp;&nbsp;</td><td>VPN Client Name</td><td>VPN IP</td><td>Expiry</td><td>Public IP</td><td>Description</td><td>Customer Name</td></tr>';
echo '</table>';

echo "</body>
<script>
  window.addEventListener('load', function() {
    update_page();
  });

  $('#customer_id').change(function(){
    update_page();
  });

  function update_page() {
    $('.device_entry').remove();
    $('.user_entry').remove();
    var customer_id = $('#customer_id').val();
    $.ajax({
      url: 'get_customer_clients.php',
      type: 'GET',
      data: {
        customer_id: customer_id
      },
      cache: false,
      success: function(data){
        data = JSON.parse(data);
        data['devices'].forEach(function (d, i){
          var row = '<tr class=\"device_entry\"><td>' + (i+1) + '</td>';
          row += '<td>' + d['common_name'] + '</td>';
          row += '<td>' + d['ip'] + '</td>';
          row += '<td>' + d['cert_expiry'] + '</td>';
          row += '<td>' + d['public_ip'] + '</td>';
          row += '<td>' + d['description'] + '</td>';
          row += '<td>' + d['customer_name'] + '</td>';
          row += '</tr>';
          $('#client_devices tr:last').after(row);
        });

        data['users'].forEach(function (d, i){
          var row = '<tr class=\"user_entry\"><td>' + (i+1) + '</td>';
          row += '<td>' + d['common_name'] + '</td>';
          row += '<td>' + d['ip'] + '</td>';
          row += '<td>' + d['cert_expiry'] + '</td>';
          row += '<td>' + d['public_ip'] + '</td>';
          row += '<td>' + d['description'] + '</td>';
          row += '<td>' + d['customer_name'] + '</td>';
          row += '</tr>';
          $('#client_users tr:last').after(row);
        });
      },
      error: function(data){
      }
    });
  }
</script>
</html>";
?>
