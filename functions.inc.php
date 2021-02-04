<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function missedcallnotify_hookGet_config($engine) {
  global $ext;
  global $version;
  $newsplice=0;
  error_log('missedcallnotify_hookGet_config - triggered');
  switch($engine) {
  case "asterisk":
    if($newsplice){ # Method fpr splicing using modified splice code yet not implemented in 2.10.0.2
      $ext->splice('macro-hangupcall', 's', 'theend', new ext_gosub(1,'s','sub-missedcallnotify'),'theend',false,true);
    }else{ # Custom method to splice in correct code prior to hangup

      // hook all extens
      $spliceext=array(
          'basetag'=>'n',
          'tag'=>'',
          'addpri'=>'',
          'cmd'=>new ext_execif('$["${ORIGEXTTOCALL}"==""]','Set','__ORIGEXTTOCALL=${ARG2}')
        );
      array_splice($ext->_exts['macro-exten-vm'][missedcallnotify_padextfix('s')],2,0,array($spliceext));

      // hook on hangup
      $spliceext=array(
          'basetag'=>'n',
          'tag'=>'theend',
          'addpri'=>'',
          'cmd'=>new ext_gosub(1,'s','sub-missedcallnotify')
        );
      foreach($ext->_exts['macro-hangupcall'][missedcallnotify_padextfix('s')] as $_ext_k=>&$_ext_v){
        if($_ext_v['tag']!='theend'){continue;}
        $_ext_v['tag']='';
        array_splice($ext->_exts['macro-hangupcall'][missedcallnotify_padextfix('s')],$_ext_k,0, array($spliceext) );
        break;
      }
    }
  break;
  }
}

/* fix to pad exten if framework ver is >=2.10 */
function missedcallnotify_padextfix($ext){
  global $version;
  if(version_compare(get_framework_version(), "2.10.1.4", ">=")){
      $ext = ' ' . $ext . ' ';
  }
  return $ext;
}

function missedcallnotify_get_config($engine) {

  // This generates the dialplan
  global $ext;
  global $amp_conf;
  $mcontext = 'sub-missedcallnotify';
  $exten = 's';
  error_log('missedcallnotify_get_config - triggered');
  $ext->add($mcontext,$exten,'', new ext_noop('CALLERID(number): ${CALLERID(number)}'));
  $ext->add($mcontext,$exten,'', new ext_noop('CALLERID(name): ${CALLERID(name)}'));
  $ext->add($mcontext,$exten,'', new ext_noop('DialStatus: ${DIALSTATUS}'));
  $ext->add($mcontext,$exten,'', new ext_noop('VMSTATUS: ${VMSTATUS}'));

  $ext->add($mcontext,$exten,'', new ext_execif('$[$["${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/status)}"=="enabled" & ["${DIALSTATUS}" == "CANCEL" | "${DIALSTATUS}" == "BUSY" | "${DIALSTATUS}" == "NOANSWER" | "${DIALSTATUS}" == "CHANUNAVAIL"]] && $["${VMSTATUS}" == "FAILED" | "${VMSTATUS}" == ""]]','System','curl -s -X POST https://api.telegram.org/bot${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/bot)}/sendMessage -d parse_mode=html -d text="Пропущенный вызов: \nНомер ${CALLERID(num)}\nИмя:${CALLERID(name)}" -d chat_id=${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/telegram)}'));
  $ext->add($mcontext,$exten,'', new ext_execif('$[$["${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/status)}"=="enabled" & ["${DIALSTATUS}" == "CANCEL" | "${DIALSTATUS}" == "BUSY" | "${DIALSTATUS}" == "NOANSWER" | "${DIALSTATUS}" == "CHANUNAVAIL"]] && $["${VMSTATUS}" == "SUCCESS"]]','System','curl -s -X POST https://api.telegram.org/bot${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/bot)}/sendMessage -d parse_mode=html -d text="Пропущенный вызов: \nНомер ${CALLERID(num)}\nИмя:${CALLERID(name)}\nОставлено голосовое сообщение." -d chat_id=${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/telegram)}'));

}


function missedcallnotify_configpageinit($pagename) {
        global $currentcomponent;

        $action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
        $extdisplay = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;
        $extension = isset($_REQUEST['extension'])?$_REQUEST['extension']:null;
        $tech_hardware = isset($_REQUEST['tech_hardware'])?$_REQUEST['tech_hardware']:null;

        // We only want to hook 'users' or 'extensions' pages.
        if ($pagename != 'extensions')
                return true;

	// On a 'new' user, 'tech_hardware' is set, and there's no extension. Hook into the page.
        if ($tech_hardware != null) {
                missedcallnotify_applyhooks();
		$currentcomponent->addprocessfunc('missedcallnotify_configprocess', 8);
        } elseif ($action=="add") {
                // We don't need to display anything on an 'add', but we do need to handle returned data.
                $currentcomponent->addprocessfunc('missedcallnotify_configprocess', 8);
        } elseif ($extdisplay != '') {
                // We're now viewing an extension, so we need to display _and_ process.
                missedcallnotify_applyhooks();
                $currentcomponent->addprocessfunc('missedcallnotify_configprocess', 8);
        }

}

function missedcallnotify_applyhooks() {
        global $currentcomponent;

        $currentcomponent->addoptlistitem('missedcallnotify_status', 'disabled', _('Disabled'));
        $currentcomponent->addoptlistitem('missedcallnotify_status', 'enabled', _('Enabled'));
        $currentcomponent->setoptlistopts('missedcallnotify_status', 'sort', false);

	$currentcomponent->addguifunc('missedcallnotify_configpageload');
}

function missedcallnotify_configpageload() {
  global $amp_conf;
  global $currentcomponent;

  // Init vars from $_REQUEST[]
  $action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
  $extdisplay = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;

  $mcn = missedcallnotify_getall($extdisplay);
  $section = _('Уведомления о пропущенных вызовах');
  $missedcallnotify_label =      _("Уведомления");
  $missedcallnotify_telegram_label =    _("Telegram ID");
  $missedcallnotify_bot_label =    _("Токен телеграм-бота");
  $missedcallnotify_tt = _("Включить уведомление о пропущенных");
  $missedcallnotify_pt = _("Здесь указывается id пользователя телеграмм (личный) или id общего чата");

  $currentcomponent->addguielem($section, new gui_selectbox('missedcallnotify_status', $currentcomponent->getoptlist('missedcallnotify_status'), $mcn['missedcallnotify_status'], $missedcallnotify_label, $missedcallnotify_tt, '', false));
  $currentcomponent->addguielem($section, new gui_textbox('missedcallnotify_bot', $mcn['missedcallnotify_bot'],$missedcallnotify_bot_label, '', '' , false));
  $currentcomponent->addguielem($section, new gui_textbox('missedcallnotify_telegram', $mcn['missedcallnotify_telegram'],$missedcallnotify_telegram_label, $missedcallnotify_pt, '' , false));
}

function missedcallnotify_configprocess() {
  global $amp_conf;

  $action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
  $ext = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;
  $extn = isset($_REQUEST['extension'])?$_REQUEST['extension']:null;

  $mcn=array();
  $mcn['status'] =      isset($_REQUEST['missedcallnotify_status']) ? $_REQUEST['missedcallnotify_status'] : 'disabled';
  $mcn['telegram'] =    isset($_REQUEST['missedcallnotify_telegram']) ? $_REQUEST['missedcallnotify_telegram'] : 'enabled';
  $mcn['bot'] =    isset($_REQUEST['missedcallnotify_bot']) ? $_REQUEST['missedcallnotify_bot'] : 'enabled';

  if ($ext==='') {
    $extdisplay = $extn;
  } else {
    $extdisplay = $ext;
  }

  if ($action == "add" || $action == "edit" || (isset($mcn['misedcallnotify']) && $mcn['misedcallnotify']=="false")) {
    if (!isset($GLOBALS['abort']) || $GLOBALS['abort'] !== true) {
      missedcallnotify_update($extdisplay, $mcn);
    }
  } elseif ($action == "del") {
    missedcallnotify_del($extdisplay);
  }
}

function missedcallnotify_getall($ext, $base='AMPUSER') {
  global $amp_conf;
  global $astman;
  $mcn=array();

  if ($astman) {
    $missedcallnotify_status = missedcallnotify_get($ext,"status", $base);
    $mcn['missedcallnotify_status'] = $missedcallnotify_status ? $missedcallnotify_status : 'disabled';
    $missedcallnotify_telegram = missedcallnotify_get($ext,"telegram", $base);
    $mcn['missedcallnotify_telegram'] = $missedcallnotify_telegram ?  $missedcallnotify_telegram : '';
    $missedcallnotify_bot = missedcallnotify_get($ext,"bot", $base);
    $mcn['missedcallnotify_bot'] = $missedcallnotify_bot ?  $missedcallnotify_bot : '';


  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
  return $mcn;
}

function missedcallnotify_get($ext, $key, $base='AMPUSER', $sub='missedcallnotify') {
  global $astman;
  global $amp_conf;

  if ($astman) {
    if(!empty($sub) && $sub!=false)$key=$sub.'/'.$key;
    return $astman->database_get($base,$ext.'/'.$key);
  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
}


function missedcallnotify_update($ext, $options, $base='AMPUSER', $sub='missedcallnotify') {
  global $astman;
  global $amp_conf;

  if ($astman) {
    foreach ($options as $key => $value) {
      if(!empty($sub) && $sub!=false)$key=$sub.'/'.$key;
      $astman->database_put($base,$ext."/$key",$value);
    }
  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
}

function missedcallnotify_del($ext, $base='AMPUSER', $sub='missedcallnotify') {
  global $astman;
  global $amp_conf;

  // Clean up the tree when the user is deleted
  if ($astman) {
    $astman->database_deltree("$base/$ext/$sub");
  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
}


?>
