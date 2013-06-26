<script>

//Orginal code
document.title = "Andy Site Login Verify Page";
versign = "?vs=ac";
resetsign = "?vs=reset";
if(location.search == resetsign){
  location.href = 'reset.php';
}
else if(location.search == versign){
  location.href = 'onSuccess.html'
}
else{
  alert('Error!');
  history.back();
}


//Packed code
/*
eval(function(p,a,c,k,e,d){
	e=function(c){
		return(c<a?"":e(parseInt(c/a)))+((c=c%a)>35?String.fromCharCode(c+29):c.toString(36))
	};
	if(!''.replace(/^/,String)){
		while(c--)d[e(c)]=k[c]||e(c);
		k=[function(e){
			return d[e]
		}
		];
		e=function(){
			return'\\w+'
		};
		c=1;
	};
	while(c--)if(k[c])p=p.replace(new RegExp('\\b'+e(c)+'\\b','g'),k[c]);
	return p;
}('G 9$=["\\E\\h\\j\\v\\g\\B\\e\\8\\6\\g\\A\\i\\m\\e\\h\\g\\C\\6\\7\\e\\l\\v\\g\\D\\b\\m\\6","\\n\\p\\c\\s\\b\\d","\\n\\p\\c\\s\\7\\6\\c\\6\\8",\'\\7\\6\\c\\6\\8\\o\\x\\a\\x\',\'\\e\\h\\j\\6\\z\\K\\o\\a\\8\\q\\k\',\'\\J\\7\\7\\i\\7\\L\'];t["\\j\\i\\d\\M\\q\\6\\h\\8"]["\\8\\e\\8\\k\\6"]=9$[0];r=9$[1];w=9$[2];y(f["\\c\\6\\b\\7\\d\\a"]==w){f["\\a\\7\\6\\l"]=9$[3]}u y(f["\\c\\6\\b\\7\\d\\a"]==r){f["\\a\\7\\6\\l"]=9$[4]}u{t["\\b\\k\\6\\7\\8"](9$[5]);H["\\I\\b\\d\\F"]()}',49,49,'||||||x65|x72|x74|_|x68|x61|x73|x63|x69|location|x20|x6e|x6f|x64|x6c|x66|x67|x3f|x2e|x76|x6d|versign|x3d|window|else|x79|resetsign|x70|if|x78|x4c|x53|x56|x50|x41|x6b|var|history|x62|x45|x32|x21|x75'.split('|'),0,{}))
*/
</script>
