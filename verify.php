<script>
versign = "?vs=ac";
if(location.search != versign){
  if(location.search == "?vs=forget"){
    location.href = 'reset.php';
  }
  alert('Error!');
  history.back();
}
else{
  location.href = 'index2.html'
}
</script>
