<?php
// helper to respect scheduled_debug
if (!function_exists('ss_debug')) {
    function ss_debug($payload) {
        try {
            $rc = rcmail::get_instance();
            if ($rc && $rc->config->get('scheduled_debug', false)) {
                ss_debug($payload);
            }
        } catch (Exception $e) {}
    }
}
// Worker include for Scheduled Sending
// Processes due messages with duplicate-send protection and retry/backoff.

trait scheduled_sending_worker_trait {
    private function run_send_due_worker()
    {
        $rc  = $this->rc;
        $cfg = $rc->config;
        $db  = $rc->get_dbh();

        $table   = $cfg->get('db_table_scheduled_sending', 'scheduled_queue');
        $batch   = (int)$cfg->get('scheduled_sending_batch', 25);
        if ($batch < 1) $batch = 25;
        $delivery = $cfg->get('scheduled_sending_delivery', 'smtp'); // 'smtp' | 'mail' | 'none'
        $this->log('worker start', array('delivery'=>$delivery,'batch'=>$batch));

        // Pick due rows in UTC
        $batch_int = (int)$batch; if ($batch_int < 1) $batch_int = 25;
$sql = "SELECT id, user_id, identity_id, scheduled_at, raw_mime, meta_json, status
                  FROM " . $table . "
                 WHERE status='queued' AND scheduled_at <= UTC_TIMESTAMP()
                 ORDER BY scheduled_at ASC
                 LIMIT " . $batch_int;
$res = $db->query($sql);if (!$res) {
            $this->log('worker scan', array('time'=>gmdate('c'), 'count'=>0));
            return array('ok'=>true, 'count'=>0);
        }

        $rows = array();
        while ($arr = $db->fetch_assoc($res)) $rows[] = $arr;
        $this->log('worker scan', array('time'=>gmdate('c'), 'count'=>count($rows)));
        if (!count($rows)) return array('ok'=>true,'count'=>0);

        $processed = 0; $sent_ok = 0; $failed = 0; $last_err = null;
        foreach ($rows as $row) {
            $id = (int)$row['id'];

            // Flip to 'sending' so we don't repick in parallel
            $db->query("DELETE FROM $table WHERE id=?", $id);
            $this->log('worker pick', array('id'=>$id));

            $raw = $row['raw_mime'];
            $meta = array();
            if (!empty($row['meta_json'])) {
                $tmp = json_decode($row['meta_json'], true);
                if (is_array($tmp)) $meta = $tmp;
            }

            // Split raw into headers/body
            $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
            $raw_headers = isset($parts[0]) ? $parts[0] : '';
            $raw_body    = isset($parts[1]) ? $parts[1] : '';

            // Parse headers with unfolding (handles multi-line/continued headers per RFC 5322)
            $headers_arr = array();
            $current = '';
            foreach (preg_split("/\r?\n/", $raw_headers) as $line) {
                if ($line === '') continue;
                if ($line[0] === ' ' || $line[0] === "\t") {
                    // continuation
                    $current .= ' ' . trim($line);
                } else {
                    if ($current !== '') {
                        if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $current, $mm)) {
                            $name = $mm[1]; $value = $mm[2];
                            if (isset($headers_arr[$name])) $headers_arr[$name] .= ', ' . $value;
                            else $headers_arr[$name] = $value;
                        }
                    }
                    $current = $line;
                }
            }
            if ($current !== '') {
                if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $current, $mm)) {
                    $name = $mm[1]; $value = $mm[2];
                    if (isset($headers_arr[$name])) $headers_arr[$name] .= ', ' . $value;
                    else $headers_arr[$name] = $value;
                }
            }

            // Envelope sender
$env_from = '';
if (isset($headers_arr['From'])) $env_from = $headers_arr['From'];
if (preg_match('/<([^>]+)>/', $env_from, $mm)) $env_from = $mm[1];
$env_from = trim($env_from);
// Recipients from headers
            $to  = isset($headers_arr['To'])  ? $headers_arr['To']  : '';
            $cc  = isset($headers_arr['Cc'])  ? $headers_arr['Cc']  : '';
            $bcc = isset($headers_arr['Bcc']) ? $headers_arr['Bcc'] : '';
            $rcpts_hdr = array();
            foreach (array($to, $cc, $bcc) as $_list) {
                if (!$_list) continue;
                foreach (preg_split('/\s*,\s*/', $_list) as $_addr) {
                    $_addr = trim($_addr);
                    if ($_addr === '') continue;
                    if (preg_match('/<([^>]+)>/', $_addr, $mm)) $_addr = $mm[1];
                    if (strpos($_addr, '@') !== false) $rcpts_hdr[] = $_addr;
                }
            }

            // Recipients from meta_json fallback
            $rcpts_meta = array();
            if (!empty($meta) && is_array($meta)) {
                foreach (array('to','cc','bcc') as $_k) {
                    if (!empty($meta[$_k])) {
                        foreach (preg_split('/\s*,\s*/', $meta[$_k]) as $_addr) {
                            $_addr = trim($_addr);
                            if ($_addr === '') continue;
                            if (preg_match('/<([^>]+)>/', $_addr, $mm)) $_addr = $mm[1];
                            if (strpos($_addr, '@') !== false) $rcpts_meta[] = $_addr;
                        }
                    }
                }
            }

            $rcpts_all = array_values(array_unique(array_merge($rcpts_hdr, $rcpts_meta)));

            // Ensure required headers
            if (empty($headers_arr['Date'])) {
                $headers_arr['Date'] = gmdate('D, d M Y H:i:s').' +0000';
                $headers_arr['X-SS-Patched-Date'] = '1';
            }
            if (empty($headers_arr['Message-ID']) && !empty($env_from)) {
                $dom = substr(strrchr($env_from, '@'), 1);
                if (!$dom) $dom = 'localhost';
                $headers_arr['Message-ID'] = '<ss-'.bin2hex(random_bytes(8)).'@'.$dom.'>';
            }

            // Remove Bcc header for on-the-wire message
            if (isset($headers_arr['Bcc'])) unset($headers_arr['Bcc']);

            $ok = false;
            $err = '';

            try {
                if ($delivery === 'smtp' && class_exists('rcube_smtp')) {
                    $smtp = new rcube_smtp($rc->config);
                    if (method_exists($smtp, 'send_mail')) {
                        if (empty($rcpts_all)) { $ok = false; $err = 'no recipients found'; }
                        else {
                            if (!$env_from) $env_from = isset($headers_arr['From']) ? $headers_arr['From'] : '';
                            if (!$env_from) { $ok = false; $err = 'no envelope sender'; }
                            if (preg_match('/<([^>]+)>/', $env_from, $mm)) $env_from = $mm[1];
                            $env_from = trim($env_from);
                            if (!$env_from) $env_from = 'mailer-daemon@localhost';
                            $ok = $smtp->send_mail($env_from, $rcpts_all, $headers_arr, $raw_body);
                        }
                    } elseif (method_exists($smtp, 'send_message')) {
                        $ok = $smtp->send_message($env_from, $rcpts_all, $raw);
                    } else {
                        $ok = false;
                        $err = 'rcube_smtp: no send_* method';
                    }
                    if (!$ok && method_exists($smtp, 'get_error')) {
                        $e = $smtp->get_error();
                        if ($e) $err = json_encode($e);
                    }
                
                    if (!$ok && empty($err)) { $err = 'smtp send_mail returned false'; }

                if (!$ok) {
                    $prev_err = $err;
                    // SMTP failed: try PHP mail() as a fallback
                    $to_hdr = isset($headers_arr['To']) ? $headers_arr['To'] : (count($rcpts_all) ? implode(', ', $rcpts_all) : '');
                    $subject = isset($headers_arr['Subject']) ? $headers_arr['Subject'] : '(no subject)';
                    $hdr_arr = $headers_arr;
                    unset($hdr_arr['To'], $hdr_arr['Subject'], $hdr_arr['Bcc']);
                    $hdr_lines = array();
                    foreach ($hdr_arr as $k=>$v) $hdr_lines[] = $k.': '.$v;
                    $hdr_str = implode("
", $hdr_lines);
                    $ok = mail($to_hdr, $subject, $raw_body, $hdr_str, '-f' . $env_from);
                    if ($ok) {
                        $err = '';
                        $this->log('fallback mail() sent', array('id'=>$id));
                    } else {
                        if (empty($prev_err)) $prev_err = 'smtp send_mail returned false';
                        $err = 'smtp failed; mail() also failed: ' . $prev_err;
                    }
                }
} elseif ($delivery === 'mail' || $delivery === 'none' || !class_exists('rcube_smtp')) {
                    $this->log('mail attempt', array('id'=>$id,'delivery'=>$delivery,'have_smtp'=>class_exists('rcube_smtp')));
                    if ($delivery === 'none') {
                        $ok = true; // dry-run
                    } else {
                        // Basic mail() fallback
                        $to_hdr = isset($headers_arr['To']) ? $headers_arr['To'] : '';
                        $subject = isset($headers_arr['Subject']) ? $headers_arr['Subject'] : '';
                        // Build headers string (without To/Subject/Bcc)
                        unset($headers_arr['To'], $headers_arr['Subject'], $headers_arr['Bcc']);
                        $hdr_lines = array();
                        foreach ($headers_arr as $k=>$v) $hdr_lines[] = $k.': '.$v;
                        $hdr_str = implode("\r\n", $hdr_lines);
                        $ok = mail($to_hdr, $subject, $raw_body, $hdr_str, '-f' . $env_from);
                        if (!$ok) $err = 'mail() returned false'; else if (empty($err)) { $err = 'mail() returned false (no details)'; }
                    }
                }
            } catch (Exception $ex) {
                $ok = false; $err = $ex->getMessage();
            }

            $processed++; if ($ok) {


                // IMAP append to Sent and remove Drafts copy
                try {
                    $storage = $rc->get_storage();
                    $sentmb  = $cfg->get('sent_mbox', $cfg->get('scheduled_sending_sent_folder', 'Sent'));
                    if (!empty($sentmb)) {
                        if (!$storage->folder_exists($sentmb)) {
                            $storage->create_folder($sentmb, true);
                        }
                        $storage->save_message($sentmb, $raw);
                    }
                    $drafts = $cfg->get('drafts_mbox', 'Drafts');
                    if (!empty($drafts)) {
                        // Prefer direct UID if captured when draft was saved
                        $draft_uid = null;
                        if (isset($meta) && is_array($meta) && !empty($meta['draft_uid'])) {
                            $draft_uid = (int)$meta['draft_uid'];
                        }
                        if ($draft_uid) {
                            $storage->delete_message($draft_uid, $drafts);
                        } else {
                            // Fallback: search by Message-ID header
                            $msgid = '';
                            if (isset($headers_arr['Message-ID'])) $msgid = $headers_arr['Message-ID'];
                            if (!$msgid) {
                                // parse from raw headers if needed
                                if (preg_match('/^Message-ID:\s*(.+)$/mi', $raw, $mm)) { $msgid = trim($mm[1]); }
                            }
                            if ($msgid) {
                                $index = $storage->search($drafts, 'HEADER', array('Message-ID' => $msgid));
                                if ($index && !empty($index->count)) {
                                    foreach ($index->get() as $msg) {
                                        $storage->delete_message($msg->uid, $drafts);
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    ss_debug(array('msg'=>'imap post-send', 'err'=>$e->getMessage()));
                }

                $db->query("DELETE FROM $table WHERE id=?", $id);
                $this->log('worker sent', array('id'=>$id)); $sent_ok++;
            } else {
                $err = (string)$err; if ($err === '') { $err = 'unknown failure'; }
                $db->query("UPDATE $table SET last_error=?, updated_at=UTC_TIMESTAMP() WHERE id=?", (string)$err, $id);
                $db->query("UPDATE $table SET scheduled_at=UTC_TIMESTAMP()+INTERVAL 5 MINUTE, status='queued' WHERE id=?", $id);
                $this->log('worker retry scheduled', array('id'=>$id, 'delay'=>300, 'attempts'=>1));
                if (!empty($err)) { $this->log('worker retry reason', array('id'=>$id,'error'=>$err)); $last_err = (string)$err; } $failed++;
            }
        }

        return array('ok'=>true,'processed'=>$processed,'sent'=>$sent_ok,'failed'=>$failed,'last_error'=>$last_err);
    }
}
