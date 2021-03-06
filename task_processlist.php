<?php

/*
  All Emoncms code is released under the GNU Affero General Public License.
  See COPYRIGHT.txt and LICENSE.txt.
  ---------------------------------------------------------------------
  Emoncms - open source energy visualisation
  Part of the OpenEnergyMonitor project: http://openenergymonitor.org
 */

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

// Schedule Processlist Module
class Task_ProcessList {

    private $log;
    private $mysqli;
    private $feed;
    private $input;
    private $task;
    private $proc_goto;          // goto step in process list

// Module required constructor, receives parent as reference

    public function __construct(&$parent) {
        global $redis;

        $this->log = new EmonLogger(__FILE__);
        $this->mysqli = &$parent->mysqli;
        $this->feed = &$parent->feed;
        $this->input = &$parent->input;
        $this->parentProcessModel = &$parent;
        $this->proc_goto = &$parent->proc_goto;
        require_once "Modules/task/task_model.php";
        $this->task = new Task($this->mysqli, $redis, null);
    }

// Module required process configuration, $list array index position is not used, function name is used instead
    public function process_list() {
// 0=>Name | 1=>Arg type | 2=>function | 3=>No. of datafields if creating feed | 4=>Datatype | 5=>Group | 6=>Engines | 'desc'=>Description | 'requireredis'=>true | 'nochange'=>true  | 'helpurl'=>"http://..."
        $list[] = array(_("Get feed id"), ProcessArg::FEEDID, "get_feed_id", 0, DataType::UNDEFINED, "Get id", 'desc' => _("<p>Passes the id of the selected feed to the next process </p>"), 'requireredis' => false, 'nochange' => false);
        $list[] = array(_("Get input id"), ProcessArg::INPUTID, "get_input_id", 0, DataType::UNDEFINED, "Get id", 'desc' => _("<p>Passes the id of the selected input to the next process </p>"), 'requireredis' => false, 'nochange' => false);
        $list[] = array(_("If feed last update > secs, go to next"), ProcessArg::VALUE, "feed_last_update_greater", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the feed hasn't been updated for the specified amount of seconds. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid feed id (see <i>Get feed id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("If input last update > secs, go to next"), ProcessArg::VALUE, "input_last_update_greater", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the input hasn't been updated for the specified amount of seconds. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid input id (see <i>Get input id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("Send email about a feed"), ProcessArg::TEXT, "send_email_feed", 0, DataType::UNDEFINED, "Notifications (id passed as value)", 'desc' => _("<p>Send an email to the user with the specified body.</p><p>Supported template tags to customize body: {current_time}, {name}, {id}, {last_update}, {value}</p><p>Example body text: At {current_time}, the last update of {name} (feed id: {id}) was on {last_update} and it's value was {value}.</p><p><b>It requires that the value passsed from previous process is a valid feed id (see <i>Get feed id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("Send email about an input"), ProcessArg::TEXT, "send_email_input", 0, DataType::UNDEFINED, "Notifications (id passed as value)", 'desc' => _("<p>Send an email to the user with the specified body.</p><p>Supported template tags to customize body: {id}, {key}, {name}, {node}, {current_time}, {value}, {last_update}</p><p>Example body text: At {current_time} your input from node {node} with key {key} named {name} had value {value} and was last updated {last_update}.</p><p><b>It requires that the value passsed from previous process is a valid input id (see <i>Get input id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("If feed last value >, go to next"), ProcessArg::VALUE, "feed_last_value_greater", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the feed is greater than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid feed id (see <i>Get feed id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("If feed last value <, go to next"), ProcessArg::VALUE, "feed_last_value_less", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the feed is less than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid feed id (see <i>Get feed id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("If input last value >, go to next"), ProcessArg::VALUE, "input_last_value_greater", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the input is greater than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid input id (see <i>Get input id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("If input last value <, go to next"), ProcessArg::VALUE, "input_last_value_less", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the input is less than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid input id (see <i>Get input id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
        $list[] = array(_("EXIT"), ProcessArg::NONE, "error_found_access_forbidden", 0, DataType::UNDEFINED, "Hidden", 'desc' => "<p>This was automaticaly added because a user's task was trying to acces a feed or input that the user has no access to.</p>", 'internalerror' => true, 'internalerror_reason' => "NO ACCESS TO FEED/INPUT", 'internalerror_desc' => 'Processlist disabled as it uses a feed/input the user has no access to.');
        return $list;
    }

// Below are functions of this module processlist, same name must exist on process_list()

    public function get_feed_id($feedid, $time, $value, $options) {
        global $session;
        if ($this->user_has_access_to_feed($session['userid'], $feedid)) {
            return $feedid;
        }
        else {
            $this->adderror_log_end('get_feed_id', $options);
            return false;
        }
    }

    public function get_input_id($inputid, $time, $value, $options) {
        global $session;
        if ($this->input->belongs_to_user($session['userid'], $inputid)) {
            return $inputid;
        }
        else {
            $this->adderror_log_end('get_input_id', $options);
            return false;
        }
    }

    public function feed_last_update_greater($secs, $time, $value, $options) { // $value must be feedid
        global $session;
        if ($this->user_has_access_to_feed($session['userid'], $value)) {
            $last_update = $this->feed->get_timevalue($value);
            if ((time() - $last_update['time']) < $secs)
                $this->proc_goto = PHP_INT_MAX;
            return $value;
        }
        else {
            $this->adderror_log_end('feed_last_update_greater', $options);
            return false;
        }
    }

    public function input_last_update_greater($secs, $time, $value, $options) {// $value must be inputid
        global $session;
        if ($this->input->belongs_to_user($session['userid'], $value)) {
            $result = $this->mysqli->query("SELECT time FROM input WHERE `id` = '$value'");
            $row = $result->fetch_array();
            $last_update = $row['time'];
            if ((time() - $last_update) < $secs)
                $this->proc_goto = PHP_INT_MAX;
            return $value;
        }
        else {
            $this->adderror_log_end('input_last_update_greater', $options);
            return false;
        }
    }

    public function send_email_feed($emailbody, $time, $feedid, $options) {// $feedid is the value passed from previous process,  it must be a valid feedid!!!
        global $user, $session;
        if ($this->user_has_access_to_feed($session['userid'], $feedid)) {
            $timeformated = DateTime::createFromFormat("U", (int) $time);
            $timeformated->setTimezone(new DateTimeZone($this->parentProcessModel->timezone));
            $timeformated = $timeformated->format("Y-m-d H:i:s");

            $feed_data = $this->feed->get($feedid);
            $last_update = DateTime::createFromFormat("U", (int) $feed_data['time']);
            $last_update->setTimezone(new DateTimeZone($this->parentProcessModel->timezone));
            $last_update = $last_update->format("Y-m-d H:i:s");

            $tag = array("{name}", "{id}", "{current_time}", "{value}", "{last_update}");
            $replace = array($feed_data['name'], $feedid, $timeformated, $feed_data['value'], $last_update);
            $emailbody = str_replace($tag, $replace, $emailbody);

            $emailto = $user->get_email($session['userid']);
            require_once "Lib/email.php";
            $email = new Email();
//$email->from(from);
            $email->to($emailto);
            $email->subject('emonCMS notification');
            $email->body($emailbody);
            $result = $email->send();
            if (!$result['success']) {
                $this->log->error("Email send returned error. message='" . $result['message'] . "'");
            }
            else {
                $this->log->info("Email sent to $emailto");
            }
            return $feedid;
        }
        else {
            $this->adderror_log_end('send_email_feed', $options);
            return false;
        }
    }

    public function send_email_input($emailbody, $time, $inputid, $options) {// $inputid is the value passed from previous process,  it must be a valid inputid!!!
        global $user, $session;
        if (!$this->input->belongs_to_user($session['userid'], $inputid)) {
            $this->adderror_log_end('send_email_input', $options);
            return false;
        }
        else {
            $timeformated = DateTime::createFromFormat("U", (int) $time);
            $timeformated->setTimezone(new DateTimeZone($this->parentProcessModel->timezone));
            $timeformated = $timeformated->format("Y-m-d H:i:s");

            $result = $this->mysqli->query("SELECT time FROM input WHERE `id` = '$inputid'");
            $row = $result->fetch_array();
            $last_update = (int) $row['time'];
            $last_update = DateTime::createFromFormat("U", $last_update);
            $last_update->setTimezone(new DateTimeZone($this->parentProcessModel->timezone));
            $last_update = $last_update->format("Y-m-d H:i:s");

            $last_value = $this->parentProcessModel->input->get_last_value($inputid);

            $inputdetails = $this->parentProcessModel->input->get_details($inputid);

            $tag = array("{key}", "{id}", "{current_time}", "{value}", "{last_update}", "{name}", "{node}");
            $replace = array($inputdetails['name'], $inputid, $timeformated, $last_value, $last_update, $inputdetails['description'], $inputdetails['nodeid']);
            $emailbody = str_replace($tag, $replace, $emailbody);

            $emailto = $user->get_email($session['userid']);
            require_once "Lib/email.php";
            $email = new Email();
//$email->from(from);
            $email->to($emailto);
            $email->subject('emonCMS notification');
            $email->body($emailbody);
            $result = $email->send();
            if (!$result['success']) {
                $this->log->error("Email send returned error. message='" . $result['message'] . "'");
            }
            else {
                $this->log->info("Email sent to $emailto");
            }
            return $inputid;
        }
    }

    /* $list[] = array(_("If feed last value >, go to next"), ProcessArg::VALUE, "feed_last_value_greater", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the feed is greater than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid feed id (see <i>Get feed id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
      $list[] = array(_("If feed last value <, go to next"), ProcessArg::VALUE, "feed_last_value_less", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the feed is less than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid feed id (see <i>Get feed id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
      $list[] = array(_("If input last value >, go to next"), ProcessArg::VALUE, "input_last_value_greater", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the input is greater than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid input id (see <i>Get input id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
      $list[] = array(_("If input last value <, go to next"), ProcessArg::VALUE, "input_last_value_less", 0, DataType::UNDEFINED, "Conditional (id passed as value)", 'desc' => _("<p>The execution of the processlist will carry on if the last value of the input is less than the specified. Otherwise it will stop. </p><p><b>The value passsed from previous process must be a valid input id (see <i>Get input id</i>)</b></p>"), 'requireredis' => false, 'nochange' => true);
     */

    public function feed_last_value_greater($cond_value, $time, $feedid, $options) {// $feedid is the value passed from previous process,  it must be a valid feedid!!!
        if ($this->user_has_access_to_feed($session['userid'], $feedid)) {
            $last_value = $this->feed->get_timevalue($feedid);
            if ($last_value['value'] < $cond_value)
                $this->proc_goto = PHP_INT_MAX;
            return $feedid;
        }
        else {
            $this->adderror_log_end('feed_last_value_greater', $options);
            return false;
        }
    }

    public function feed_last_value_less($cond_value, $time, $feedid, $options) {// $feedid is the value passed from previous process,  it must be a valid feedid!!!
        if ($this->user_has_access_to_feed($session['userid'], $feedid)) {
            $last_value = $this->feed->get_timevalue($feedid);
            if ($last_value['value'] > $cond_value)
                $this->proc_goto = PHP_INT_MAX;
            return $feedid;
        }

        else {
            $this->adderror_log_end('feed_last_value_less', $options);
            return false;
        }
    }

    public function input_last_value_greater($cond_value, $time, $inputid, $options) {// $inputid is the value passed from previous process,  it must be a valid inputid!!!
        global $session;

        if ($this->input->belongs_to_user($session['userid'], $inputid)) {
            $last_value = $this->parentProcessModel->input->get_last_value($inputid);
            if ($last_value < $cond_value)
                $this->proc_goto = PHP_INT_MAX;
            return $inputid;
        }
        else {
            $this->adderror_log_end('input_last_value_greater', $options);
            return false;
        }
    }

    public function input_last_value_less($cond_value, $time, $inputid, $options) {// $inputid is the value passed from previous process,  it must be a valid inputid!!!
        global $session;

        if ($this->input->belongs_to_user($session['userid'], $inputid)) {
            $last_value = $this->parentProcessModel->input->get_last_value($inputid);
            if ($last_value > $cond_value)
                $this->proc_goto = PHP_INT_MAX;
            return $inputid;
        }
        else {
            $this->adderror_log_end('input_last_value_less', $options);
            return false;
        }
    }

    public function error_found_access_forbidden($arg, $time, $value) {
        $this->proc_goto = PHP_INT_MAX;
        return $value;
    }

    private function adderror_log_end($origin_function, $options) {
        global $session;
        $task = $this->task->get_task($session['userid'], $options['sourceid']);
        $processList = $task['processList'];

        // Add error
        $this->task->set_processlist($session['userid'], $options['sourceid'], "task__error_found_access_forbidden:0," . $processList); // Add 'error_found' process to this processList.
        $this->parentProcessModel->runtime_error = ProcessError::ACCESS_FORBIDDEN;
        // Log warning
        $this->log->error("Process: task__$origin_function -- Task: " . $options['sourceid'] . " -- User " . $session['userid'] . " is trying to access a feed or input which doesn't belong to him/her");
        // End processlist execution
        $this->proc_goto = PHP_INT_MAX;
    }

    private function user_has_access_to_feed($userid, $feedid) {
        $user_feeds = $this->feed->get_user_feed_ids($userid);
        return array_search($feedid, $user_feeds) === false ? false : true;
    }

}
