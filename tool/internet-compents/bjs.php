<html><head><title>Basic JS Test</title></head><body>
<input type="button" value="Test!" onclick="test();">
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
