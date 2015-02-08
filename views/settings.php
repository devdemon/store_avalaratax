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

<p><?=form_submit('submit', lang('submit'), 'class="submit"')?></p>

<?=form_close()?>