<script>
versign = "?vs=ac";
resetsign = "?vs=reset";
if(location.search == resetsign){
  location.href = 'reset.php';
}
if(location.search != versign){
  alert('Error!');
  history.back();
}
else{
  location.href = 'index2.html'
}
</script>
