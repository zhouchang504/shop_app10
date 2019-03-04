<?php /*a:1:{s:87:"D:\phpStudy\WWW\moduleshop\application\weixin\view\sys_admin\reply_text\search_box.html";i:1549953096;}*/ ?>
<div class="modal-dialog">
    <div class="modal-content">

        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h4 class="modal-title"><i class="icon-table"></i>选择关键字</h4>
        </div>
      
        <div class="modal-body" style="padding-bottom:0px;">
           <form class="form-horizontal form-validate form-modal" method="post" id="search_form" >
            <div class="row-fluid">              
                 关键字：<input name="keyword" id="keyword" type="text" class="input-medium" placeholder="请输入关键字" />
                <button type="button" class="btn "onclick="evalList()" ><strong>查找</strong></button>
            </div>
            </form>
            
            <div class="row-fluid m-b m-t">
                共有 <span class="red" id="_count_num">0</span> 条
                <button type="button" id="p_page" class="btn btn-sm" onclick="eval_list('prev')"><strong>上一页</strong></button>
                <span id="p_page_str">第<span id="_nowPage">1</span>页/共<span id="_totalPages">1</span>页</span>
                <button type="button" id="n_page" class="btn btn-sm" onclick="eval_list('next')"><strong>下一页</strong></button>
               
            </div>    
                <table class="table table-bordered table-striped ">
                      <thead >
                       <thead>
                              <tr>
                                 <th width="80">ID</th>
                                 <th >关键字</th>
                              </tr>
                          </thead>
                      <tbody  id="data_list">
                      
                      </tbody>
                </table>
            
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-dismiss="modal">确定</button>
        </div>

    </div>
   
</div>


<script type="text/javascript">
	function evalList(ptype){
	  var arr = $('#search_form').toJson();
	  arr.p = 1;
	  if (ptype == 'prev'){
		  if ($('#_nowPage').html() == 1) return false;
		  arr.p = parseInt($('#_nowPage').html())-parseInt(1);
	  }else if (ptype == 'next'){
		  if ($('#_nowPage').html() == $('#_totalPages').html()) return false;
		  arr.p = parseInt($('#_nowPage').html())+parseInt(1);
	  }
	  $('#data_list').html('');
	  var res = jq_ajax('<?php echo url("searchBox"); ?>',arr);
	  if (res.msg) _alert(res.msg);
	  if (res.code == 0) return false;
	  showlist(res.data);
	}
	function showlist(res){
	  $('#_count_num').html(res.total_count);
	  $('#_nowPage').html(res.page);
	  $('#_totalPages').html(res.page_count);
	  $.each(res.list,function(key,val){ key
		   $('#data_list').append('<tr onclick="selTr(this,'+val.id+',\''+val.keyword+'\')" ><td>'+val.id+'</td><td>'+val.keyword+'</td></tr>');
	  })	
	}
	function selTr(obj,id,title){
		$("#data_list").find('tr').removeClass('trselect');
		$(obj).addClass('trselect');
	 	assigBack('<?php echo htmlentities($searchType); ?>','<?php echo htmlentities($_menu_index); ?>',id,title);
	}
	showlist(<?php echo json_encode($data); ?>);

</script>