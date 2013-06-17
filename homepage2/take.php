<html>
<head>
<title>Andy Administration permisson check Page</title>
</head>
<body>
For secuity,this system needs check more infomation about your ID.<br>
Please Input staff number:<input type="password" id="sn"><br>
Please Input staff password:<input type="password" id="sp"><br>
Please Input dynamic code:<font id="dcode"></font>&nbsp;<input type="password" id="dc"><br>
<input type="button" value="Submit" onclick="staff_check();">

<script>
code = Math.round((Math.random() + 5) * 1000);
function alert_code(){
 alert("The dynamic code is " + code);
}
function staff_check(){
}
alert_code();
</script>
