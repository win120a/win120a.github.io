<script language="javascript">
function gowhere1(formname) {
var url;
if (formname.myselectvalue.value == "0") {
url = "http://www.baidu.com/baidu";
document.search_form1.tn.value = "baidu";
formname.method = "get";
}
if (formname.myselectvalue.value == "1") {
url = "http://mp3.baidu.com/m";
document.search_form1.tn.value = "baidump3";
document.search_form1.ct.value = "134217728";
document.search_form1.lm.value = "-1";
}

if (formname.myselectvalue.value == "4") {
document.search_form1.tn.value = "news";
document.search_form1.cl.value = "2";
document.search_form1.rn.value = "20";
url = "http://news.baidu.com/ns";
}
if (formname.myselectvalue.value == "5") {
document.search_form1.tn.value = "baiduiamge";
document.search_form1.ct.value = "201326592";
document.search_form1.cl.value = "2";
document.search_form1.lm.value = "-1";
url = "http://image.baidu.com/i";
}
if (formname.myselectvalue.value == "6") {
url = "http://post.baidu.com/f";
document.search_form1.tn.value = "baiduPostSearch";
document.search_form1.ct.value = "352321536";
document.search_form1.rn.value = "10";
document.search_form1.lm.value = "65536";
}

formname.action = url;
return true;
}
</script>
<form onsubmit="return gowhere1(this)" target="_blank" name="search_form1" method="get" action="http://www.baidu.com/baidu">
<table width="100%" height="80" cellspacing="0" cellpadding="0" border="0" style="font-family:宋体">
<tbody><tr>
<td>
<table width="144%" height="80" cellspacing="0" cellpadding="0" border="0">
<input type="hidden" value="0" name="myselectvalue">
<input type="hidden" value="utf8" name="ie">
<input type="hidden" name="tn" value="baidu">
<input type="hidden" name="ct">
<input type="hidden" name="lm">
<input type="hidden" name="cl">
<input type="hidden" name="rn">
<tbody><tr>
<td width="8%" valign="bottom">
<div align="center">
<!-- <a href="http://www.baidu.com/">
<img border="0" align="bottom" alt="Baidu" src="http://img.baidu.com/img/logo-80px.gif">
</a> -->
</div>
</td>
<td width="92%" valign="bottom">

<input type="radio" value="0" onclick="javascript:this.form.myselectvalue.value=4;" name="myselect">
<font color="#0000cc" style="FONT-SIZE: 12px">
News
</font>

<input type="radio" value="0" onclick="javascript:this.form.myselectvalue.value=0;" name="myselect" checked="">
<span class="f12">
<font color="#0000cc" style="FONT-SIZE: 12px">
Page
</font>
</span>
<input type="radio" value="1" onclick="javascript:this.form.myselectvalue.value=1;" name="myselect">
<span class="f12">
<font color="#0000cc" style="FONT-SIZE: 12px">
Music
</font>
</span>
<input type="radio" value="0" onclick="javascript:this.form.myselectvalue.value=6;" name="myselect">
<font color="#0000cc" style="FONT-SIZE: 12px">
BBS
</font>

<!-- <input type="radio" value="0" onclick="javascript:this.form.myselectvalue.value=5;" name="myselect">
<font color="#0000cc" style="FONT-SIZE: 12px">
Photos
</font> -->

<table width="100%" cellspacing="0" cellpadding="0" border="0" align="right">
<tbody>
<tr>
<td>
<font style="FONT-SIZE: 12px">
<input size="40" name="word" id="word">
</font>
<input type="submit" value="Baidu!">

</td>
<td>
<br>
</td>
</tr>
</tbody></table>
</td>
</tr>
<tr>
<td width="8%"></td>
<td width="92%"></td>
</tr>

<tr>
<td>
</td></tr>
</tbody></table>
</td>
</tr>
</tbody></table>
</form>
