<script>
versign = "?vs=ac";
resetsign = "?vs=reset";
if(location.search == resetsign){
  location.href = 'reset.php';
}
if(location.search == versign){
  location.href = 'index2.html'
}
else{
  alert('Error!');
  history.back();
}
</script>
