<html>
<head>

<script>
var _hmt = _hmt || [];
(function() {
  var hm = document.createElement("script");
  hm.src = "//hm.baidu.com/hm.js?235d9813ec06f146ab752389d01f3492";
  var s = document.getElementsByTagName("script")[0]; 
  s.parentNode.insertBefore(hm, s);
})();
</script>
<title>Basic JS Test</title>
</head>

<body>
<a href="javascript:test();">Test!</a>
<br><br>
Process:<br>
"Test alert!" -> "Test confirm!" -> true or false -> A new window. -> show "This is new window!" -> "Test Prompt!"<br>
-> Your input data. -> "Repeat!" x 2<br><br>
If show these process,your browser basic JavaScript is normal.

<script>
function test(){
  alert("Test alert!");
  alert(confirm("Test confirm!"));
  open('').document.write("This is new window!");
  alert(prompt("Test Prompt!",""));
  for(i = 0;i < 2;i++;){
    alert("Repeat!");
  }
}

</script>
</body>
</html>
