<?=form_open('C=addons_extensions'.AMP.'M=save_extension_settings'.AMP.'file=store_avalaratax');?>

<?php
$this->table->set_template($cp_pad_table_template);
$this->table->set_heading(
    array('data' => lang('preference'), 'style' => 'width:30%;'),
    lang('setting')
);

foreach ($settings as $key => $val)
{
    $label = lang($key, $key);
    if (store_lang_exists($key . '_subtext')) {
        $label .= '<br><small>' . lang($key . '_subtext') . '</small>';
    }

    $this->table->add_row($label, $val);
}

echo $this->table->generate();

?>

<div class="test_avalara">

    <a href="#" class="test_avalaratax">Test Connection</a>
    <span class="loading"> - Testing please wait...</span>
    <span class="success"> - Successfully connected!</span>
    <span class="failed"> - Failed to connect! <span class="errors"></span></span>
</div>

<p><?=form_submit('submit', lang('submit'), 'class="submit"')?></p>

<style type="text/css">
.test_avalara {
    background: #fff;
    border: 1px solid rgba(0,0,0,0.2);
    color: #37444D;
    margin: 0 0 10px;
    padding: 9px;
    font-size: 16px;
}

.loading {
    display: none;
}

.success {
    display: none;
    font-weight: bold;
}

.failed {
    display: none;
    font-weight: bold;
    color:red;
}

.errors {
    font-weight: normal;
    font-size: 11px;
}
</style>

<script type="text/javascript">
$('.test_avalaratax').click(function(evt){
    evt.preventDefault();

    var avalaraHolder = $('.test_avalara');
    var params = {};
    params.action = 'test_connection';

    $('.avalara_test').each(function(){
        params[ $(this).attr('name') ] = $(this).val();
    });

    avalaraHolder.find('.loading').show();
    avalaraHolder.find('.failed, .success').hide();
    avalaraHolder.find('.errors').empty();

    $.ajax({
        url: window.location.href,
        data: params, dataType: 'json',
        success: function(rdata){
            avalaraHolder.find('.loading').hide();

            if (rdata.success == true) {
                avalaraHolder.find('.success').show();
            } else {
                avalaraHolder.find('.failed').show();
                avalaraHolder.find('.errors').html(rdata.error);
            }
        },
        error: function() {
            avalaraHolder.find('.loading').hide();
            avalaraHolder.find('.failed').show();
        }
    }, 'json');
});
</script>

<?=form_close()?>