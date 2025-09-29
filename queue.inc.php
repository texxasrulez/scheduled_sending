<?php
// Queue viewer + actions for scheduled_sending
trait scheduled_sending_queue_trait {
    public function action_queue()
    {
        $rc = $this->rc;
        $this->include_stylesheet('skins/larry/scheduled.css');
        $this->include_script('js/queue.js');
        $rc->output->set_pagetitle($this->gettext('scheduled_queue_title'));
        $rc->output->send('scheduled_sending.queue');
    }

    public function action_queue_list()
    {
        $rc  = $this->rc;
        $cfg = $rc->config;
        $db  = $rc->get_dbh();
        $table = $cfg->get('db_table_scheduled_sending', 'scheduled_queue');
        $limit = 200;
        $q = $db->query("SELECT id, user_id, identity_id, status, scheduled_at, created_at, updated_at, meta_json, last_error FROM $table WHERE status IN ('queued','processing','error') ORDER BY scheduled_at ASC LIMIT $limit");
        $rows = array();
        while ($q && ($r = $db->fetch_assoc($q))) {
            $meta = array();
            if (!empty($r['meta_json'])) {
                $tmp = json_decode($r['meta_json'], true);
                if (is_array($tmp)) $meta = $tmp;
            }
            $rows[] = array(
                'id' => (int)$r['id'],
                'status' => (string)$r['status'],
                'scheduled_utc' => (string)$r['scheduled_at'],
                'scheduled_ts' => strtotime($r['scheduled_at']),
                'created_at' => (string)$r['created_at'],
                'updated_at' => (string)$r['updated_at'],
                'to' => isset($meta['to']) ? (string)$meta['to'] : '',
                'cc' => isset($meta['cc']) ? (string)$meta['cc'] : '',
                'bcc' => isset($meta['bcc']) ? (string)$meta['bcc'] : '',
                'subj' => isset($meta['subj']) ? (string)$meta['subj'] : '',
                'error' => (string)$r['last_error'],
            );
        }
        $rc->output->command('plugin.scheduled_sending.queue_data', $rows);
        $rc->output->send();
    }

    public function action_queue_cancel()
    {
        $rc  = $this->rc;
        $cfg = $rc->config;
        $db  = $rc->get_dbh();
        $table = $cfg->get('db_table_scheduled_sending', 'scheduled_queue');
        $id = (int) rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        if ($id > 0) {
            $db->query("UPDATE $table SET status='canceled', updated_at=NOW() WHERE id=? AND status IN ('queued','processing')", $id);
        }
        $rc->output->command('display_message', $this->gettext('queue_cancel_ok'), 'confirmation');
        $rc->output->send();
    }

    public function action_queue_reschedule()
    {
        $rc  = $this->rc;
        $cfg = $rc->config;
        $db  = $rc->get_dbh();
        $table = $cfg->get('db_table_scheduled_sending', 'scheduled_queue');
        $id = (int) rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $ts = (int) rcube_utils::get_input_value('at_ts', rcube_utils::INPUT_POST);
        if ($id > 0 && $ts > 0) {
            $utc = gmdate('Y-m-d H:i:s', $ts);
            $db->query("UPDATE $table SET status='queued', scheduled_at=?, updated_at=NOW() WHERE id=?", $utc, $id);
        }
        $rc->output->command('display_message', $this->gettext('queue_resched_ok'), 'confirmation');
        $rc->output->send();
    }

    public function action_queue_delete()
    {
        $rc  = $this->rc;
        $cfg = $rc->config;
        $db  = $rc->get_dbh();
        $table = $cfg->get('db_table_scheduled_sending', 'scheduled_queue');
        $id = (int) rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);
        $user_id = $rc->user->ID;

        if ($id > 0) {
            $sql = "DELETE FROM {$table} WHERE id = ? AND user_id = ?";
            $db->query($sql, $id, $user_id);

            if ($db->affected_rows() > 0) {
                $rc->output->command('display_message', 'Scheduled message deleted.', 'confirmation');
            } else {
                $rc->output->command('display_message', 'Failed to delete scheduled message.', 'error');
            }
        }
        $rc->output->send();
    }
}
