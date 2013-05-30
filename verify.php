<script>
versign = "?vs=ac";
if(location.search == "?vs=forget"){
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
