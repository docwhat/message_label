<?php

/**
 * @version 1.1
 * @author Denis Sobolev <dns.sobol@gmail.com>
 *
 */
class message_label extends rcube_plugin {

    public $task = 'mail|settings';
    public $rc;

    public function init() {
        $rcmail = rcmail::get_instance();
        $this->rc = $rcmail;

        $this->add_texts('localization', true);
        $this->add_hook('messages_list', array($this, 'message_set_label'));
        $this->add_hook('preferences_list', array($this, 'label_preferences'));
        $this->add_hook('preferences_save', array($this, 'label_save'));
        $this->add_hook('preferences_sections_list', array($this, 'preferences_section_list'));
        $this->add_hook('storage_init', array($this, 'flag_message_load'));

        if ($rcmail->action == '' || $rcmail->action == 'show') {
            $labellink = $this->api->output->button(array('command' => 'plugin.label_redirect', 'type' => 'link', 'class' => 'active', 'content' => $this->gettext('label_pref')));
            $this->api->add_content(html::tag('li', array('class' => 'separator_above'), $labellink), 'mailboxoptions');
        }

        if ($rcmail->action == '' && $rcmail->task == 'mail') {
            $this->add_hook('template_object_mailboxlist', array($this, 'folder_list_label'));
            $this->add_hook('render_page', array($this, 'render_labels_menu'));
        }

        $this->add_hook('startup', array($this, 'startup'));

        $this->register_action('plugin.message_label_redirect', array($this, 'message_label_redirect'));
        $this->register_action('plugin.message_label_search', array($this, 'message_label_search'));
        $this->register_action('plugin.message_label_mark', array($this, 'message_label_mark'));
        $this->register_action('plugin.message_label_move', array($this, 'message_label_move'));
        $this->register_action('plugin.message_label_delete', array($this, 'message_label_delete'));
        $this->register_action('plugin.not_label_folder_search', array($this, 'not_label_folder_search'));
        $this->register_action('plugin.message_label_setlabel', array($this, 'message_label_imap_set'));

        $this->include_script('message_label.js');
        $this->include_script('colorpicker/mColorPicker.js');

        $this->include_stylesheet($this->local_skin_path() . '/message_label.css');
    }

    /**
     * Called when the application is initialized
     * redirect to internal function
     *
     * @access  public
     */
    function startup($args) {
        $search = get_input_value('_search', RCUBE_INPUT_GET);
        if (!isset($search))
            $search = get_input_value('_search', RCUBE_INPUT_POST);

        $uid = get_input_value('_uid', RCUBE_INPUT_GET);
        $mbox = get_input_value('_mbox', RCUBE_INPUT_GET);
        $page = get_input_value('_page', RCUBE_INPUT_GET);
        $sort = get_input_value('_sort', RCUBE_INPUT_GET);

        if ($search == 'labelsearch') {
            if ($args['action'] == 'show' || $args['action'] == 'preview') {
                $uid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];
                $this->rc->output->redirect(array('_task' => 'mail', '_action' => $args['action'], '_mbox' => $mbox, '_uid' => $uid));
            }
            if ($args['action'] == 'list') {
                $this->rc->output->command('label_search', '_page=' . $page . '&_sort=' . $sort);
                $this->rc->output->send();
                $args['abort'] = true;
            }
            if ($args['action'] == 'mark') {
                $flag = get_input_value('_flag', RCUBE_INPUT_POST);
                $uid = get_input_value('_uid', RCUBE_INPUT_POST);

                $post_str = '_flag=' . $flag . '&_uid=' . $uid;
                if ($quiet = get_input_value('_quiet', RCUBE_INPUT_POST))
                    $post_str .= '&_quiet=' . $quiet;
                if ($from = get_input_value('_from', RCUBE_INPUT_POST))
                    $post_str .= '&_from=' . $from;
                if ($count = get_input_value('_count', RCUBE_INPUT_POST))
                    $post_str .= '&_count=' . $count;
                if ($ruid = get_input_value('_ruid', RCUBE_INPUT_POST))
                    $post_str .= '&_ruid=' . $ruid;

                $this->rc->output->command('label_mark', $post_str);
                $this->rc->output->send();
                $args['abort'] = true;
            }
            if ($args['action'] == 'moveto') {
                $target_mbox = get_input_value('_target_mbox', RCUBE_INPUT_POST);
                $uid = get_input_value('_uid', RCUBE_INPUT_POST);

                $post_str = '_uid=' . $uid . '&_target_mbox=' . $target_mbox;

                $this->rc->output->command('label_move', $post_str);
                $this->rc->output->send();
                $args['abort'] = true;
            }
            if ($args['action'] == 'delete') {
                $uid = get_input_value('_uid', RCUBE_INPUT_POST);

                $post_str = '_uid=' . $uid;

                $this->rc->output->command('label_delete', $post_str);
                $this->rc->output->send();
                $args['abort'] = true;
            }
        } else if ($_SESSION['label_folder_search']['uid_mboxes']) {
            // if action is empty then the page has been refreshed
            if (!$args['action']) {
                $_SESSION['label_folder_search']['uid_mboxes'] = 0;
                $_SESSION['label_id'] = 0;
            }
        }
        return $args;
    }

    /**
     * Set label by config to mail from maillist
     *
     * @access  public
     */
    function message_set_label($p) {
        $prefs = $this->rc->config->get('message_label', array());
        //write_log('debug', preg_replace('/\r\n$/', '', print_r($prefs, true)));

        if (!count($prefs) or !isset($p['messages']) or !is_array($p['messages']))
            return $p;

        foreach ($p['messages'] as $message) {
            $type = 'filter';
            $color = '';
            $ret_key = array();
            foreach ($prefs as $key => $p) {
                if ($p['header'] == 'subject') {
                    $cont = trim(rcube_mime::decode_header($message->$p['header'], $message->charset));
                } else {
                    $cont = $message->$p['header'];
                }
                if (stristr($cont, $p['input'])) {
                    array_push($ret_key, array('id' => $key, 'type' => $type));
                }
            }

            if (!empty($message->flags))
                foreach ($message->flags as $flag => $set_val) {
                    if (stripos($flag, 'ulabels') === 0) {
                        $flag_id = str_ireplace('ulabels_', '', $flag);
                        if (!empty($ret_key)) {
                            foreach ($ret_key as $key_search => $value) {
                                $id = $value['id'];
                                if ($prefs[$id]['id'] == strtolower($flag_id) && $value['type'] == 'filter')
                                    unset($ret_key[$key_search]);
                            }
                        }
                    }

                    $type = 'label';
                    if (stripos($flag, 'labels') === 0) {
                        $flag_id = str_ireplace('labels_', '', $flag);
                        foreach ($prefs as $key => $p) {
                            if ($p['id'] == strtolower($flag_id)) {
                                $flabel = false;
                                if (!empty($ret_key)) {
                                    foreach ($ret_key as $key_filter => $value_filter) {
                                        $searh_filter = array('id' => $key, 'type' => 'filter');
                                        if ($value_filter == $searh_filter)
                                            $flabel = $key_filter;
                                    }
                                }
                                if ($flabel !== false) {
                                    unset($ret_key[$flabel]);
                                    $type = 'flabel';
                                }
                                array_push($ret_key, array('id' => $key, 'type' => $type));
                            }
                        }
                    }
                }

            //write_log('debug', preg_replace('/\r\n$/', '', print_r($ret_key,true)));

            if (!empty($ret_key)) {
                sort($ret_key);
                $message->list_flags['extra_flags']['plugin_label'] = array();
                $k = 0;
                foreach ($ret_key as $label_id) {
                    !empty($p['text']) ? $text = $p['text'] : $text = 'label';
                    $id = $label_id['id'];
                    $type = $label_id['type'];
                    $message->list_flags['extra_flags']['plugin_label'][$k]['color'] = $prefs[$id]['color'];
                    $message->list_flags['extra_flags']['plugin_label'][$k]['text'] = $prefs[$id]['text'];
                    $message->list_flags['extra_flags']['plugin_label'][$k]['id'] = $prefs[$id]['id'];
                    $message->list_flags['extra_flags']['plugin_label'][$k]['type'] = $type;
                    $k++;
                }
            }
        }
        return $p;
    }

    /**
     * set flags when search labels by filter and label
     *
     */
    function message_label_imap_set() {
        if (($uids = get_input_value('_uid', RCUBE_INPUT_POST)) && ($flag = get_input_value('_flag', RCUBE_INPUT_POST))) {
            $flag = $a_flags_map[$flag] ? $a_flags_map[$flag] : strtoupper($flag);
            $type = get_input_value('_type', RCUBE_INPUT_POST);
            $label_search = get_input_value('_label_search', RCUBE_INPUT_POST);

            if ($label_search) {
                $uids = explode(',', $uids);
                // mark each uid individually because the mailboxes may differ
                foreach ($uids as $uid) {
                    $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                    $this->rc->storage->set_folder($mbox);
                    if ($type == 'flabel') {
                        $unlabel = 'UN' . $flag;
                        $marked = $this->rc->storage->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $unlabel);
                        $unfiler = 'U' . $flag;
                        $marked = $this->rc->storage->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $unfiler);
                    }
                    else
                        $marked = $this->rc->storage->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $flag);

                    if (!$marked) {
                        // send error message
                        $this->rc->output->show_message('errormarking', 'error');
                        $this->rc->output->send();
                        exit;
                    } else if (empty($_POST['_quiet'])) {
                        $this->rc->output->show_message('messagemarked', 'confirmation');
                    }
                }
            } else {
                if ($type == 'flabel') {
                    $unlabel = 'UN' . $flag;
                    $marked = $this->rc->storage->set_flag($uids, $unlabel);
                    $unfiler = 'U' . $flag;
                    $marked = $this->rc->storage->set_flag($uids, $unfiler);
                }
                else
                    $marked = $this->rc->storage->set_flag($uids, $flag);

                if (!$marked) {
                    // send error message
                    if ($_POST['_from'] != 'show')
                        $this->rc->output->command('list_mailbox');
                    rcmail_display_server_error('errormarking');
                    $this->rc->output->send();
                    exit;
                }
                else if (empty($_POST['_quiet'])) {
                    $this->rc->output->show_message('messagemarked', 'confirmation');
                }
            }

            if (!empty($_POST['_update'])) {
                $this->rc->output->command('clear_message_list');
                $this->rc->output->command('list_mailbox');
            }
        }
        $this->rc->output->send();
    }

    /**
     * Serching mail by label id
     * js function label_search
     *
     * @access  public
     */
    function message_label_search() {
        // reset list_page and old search results
        $this->rc->storage->set_page(1);
        $this->rc->storage->set_search_set(NULL);
        $_SESSION['page'] = 1;
        $page = get_input_value('_page', RCUBE_INPUT_POST);

        $page = $page ? $page : 1;

        $id = get_input_value('_id', RCUBE_INPUT_POST);

        // is there a sort type for this request?
        if ($sort = get_input_value('_sort', RCUBE_INPUT_POST)) {
            // yes, so set the sort vars
            list($sort_col, $sort_order) = explode('_', $sort);

            // set session vars for sort (so next page and task switch know how to sort)
            $save_arr = array();
            $_SESSION['sort_col'] = $save_arr['message_sort_col'] = $sort_col;
            $_SESSION['sort_order'] = $save_arr['message_sort_order'] = $sort_order;
        } else {
            // use session settings if set, defaults if not
            $sort_col = isset($_SESSION['sort_col']) ? $_SESSION['sort_col'] : $this->rc->config->get('message_sort_col');
            $sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $this->rc->config->get('message_sort_order');
        }

        isset($id) ? $_SESSION['label_id'] = $id : $id = $_SESSION['label_id'];

        $prefs = $this->rc->config->get('message_label', array());

        // get search string
        $str = '';
        foreach ($prefs as $p) {
            if ($p['id'] == $id) {
                $str = $p['input'];
                $filter = 'ALL';
                $header = $p['header'];
                $folders[0] = $p['folder'];
            }
        }

        // add list filter string
        $search_str = $filter && $filter != 'ALL' ? $filter : '';
        $_SESSION['search_filter'] = $filter;

        $subject[$header] = 'HEADER ' . $header;
        $search = $srch ? trim($srch) : trim($str);

        if ($subject) {
            $search_str .= str_repeat(' OR', count($subject) - 1);
            foreach ($subject as $sub)
                $search_str .= sprintf(" %s {%d}\r\n%s", $sub, strlen($search), $search);
            $_SESSION['search_mods'] = $subject;
        }

        $search_str = trim($search_str);
        $count = 0;
        $result_h = Array();
        $tmp_page_size = $this->rc->storage->get_pagesize();
        $this->rc->storage->set_pagesize(500);

        if ($use_saved_list && $_SESSION['all_folder_search']['uid_mboxes'])
            $result_h = $this->get_search_result();
        else
            $result_h = $this->perform_search($search_str, $folders, $id);

        $this->rc->output->set_env('label_folder_search_active', 1);
        $this->rc->storage->set_pagesize($tmp_page_size);
        $count = count($result_h);

        $this->sort_search_result($result_h);

        $result_h = $this->get_paged_result($result_h, $page);

        // Make sure we got the headers
        if (!empty($result_h)) {
            rcmail_js_message_list($result_h, false);
            if ($search_str)
                $this->rc->output->show_message('searchsuccessful', 'confirmation', array('nr' => $count));
        }
        // handle IMAP errors (e.g. #1486905)
        else if ($err_code = $this->rc->storage->get_error_code()) {
            rcmail_display_server_error();
        } else {
            $this->rc->output->show_message('searchnomatch', 'notice');
        }

        // update message count display and reset threads
        $this->rc->output->set_env('search_request', "labelsearch");
        $this->rc->output->set_env('search_labels', $id);
        $this->rc->output->set_env('messagecount', $count);
        $this->rc->output->set_env('pagecount', ceil($count / $this->rc->storage->get_pagesize()));
        $this->rc->output->set_env('threading', (bool) false);
        $this->rc->output->set_env('threads', 0);

        $this->rc->output->command('set_rowcount', rcmail_get_messagecount_text($count, $page));

        $this->rc->output->send();
        exit;
    }

    /**
     * Perform the all folder search
     *
     * @param   string   Search string
     * @return  array    Indexed array with message header objects
     * @access  private
     */
    private function perform_search($search_string, $folders, $label_id) {
        $result_h = array();
        $uid_mboxes = array();
        $id = 1;
        $result = array();
        $result_label = array();

        $search_string_label = 'KEYWORD "$labels_' . $label_id . '"';

        // Search all folders and build a final set
        if ($folders[0] == 'all' || empty($folders))
            $folders_search = $this->rc->storage->list_folders_subscribed();
        else
            $folders_search = $folders;

        foreach ($folders_search as $mbox) {

            if ($mbox == $this->rc->config->get('trash_mbox'))
                continue;

            $this->rc->storage->set_folder($mbox);

            $this->rc->storage->search($mbox, $search_string, RCMAIL_CHARSET, $_SESSION['sort_col']);
            $result = $this->rc->storage->list_messages($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);

            $this->rc->storage->search($mbox, $search_string_label, RCMAIL_CHARSET, $_SESSION['sort_col']);
            $result_label = $this->rc->storage->list_messages($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);

            if (!empty($result_label))
                foreach ($result_label as $header_obj) {
                    $add = true;
                    foreach ($result as $result_obj)
                        if ($result_obj->id == $header_obj->id) {
                            $add = false;
                            break;
                        }
                    if ($add)
                        array_push($result, $header_obj);
                }

            foreach ($result as $row) {
                $uid_mboxes[$id] = array('uid' => $row->uid, 'mbox' => $mbox);
                $row->uid = $id;
                $add_res = 1;
                $add_labels = 0;

                if (!empty($row->flags))
                    foreach ($row->flags as $flag => $set_val) {
                        if ($flag == strtoupper('ulabels_' . $label_id)) {
                            $add_res = 0;
                            break;
                        }
                    }
                if ($add_res) {
                    $result_h[] = $row;
                    $id++;
                } else {
                    foreach ($row->flags as $flag => $set_val)
                        if ($flag == strtoupper('labels_' . $label_id)) {
                            $add_labels = 1;
                            break;
                        }
                    if ($add_labels) {
                        $result_h[] = $row;
                        $id++;
                    }
                }
            }
        }

        foreach ($result_h as $set_flag) {
            $set_flag->flags['skip_mbox_check'] = true;
        }

        //write_log('debug', preg_replace('/\r\n$/', '', print_r($result_h,true)));

        $_SESSION['label_folder_search']['uid_mboxes'] = $uid_mboxes;
        $this->rc->output->set_env('label_folder_search_uid_mboxes', $uid_mboxes);

        return $result_h;
    }

    /**
     * Slice message header array to return only the messages corresponding the page parameter
     *
     * @param   array    Indexed array with message header objects
     * @param   int      Current page to list
     * @return  array    Sliced array with message header objects
     * @access  public
     */
    function get_paged_result($result_h, $page) {
        // Apply page size rules
        if (count($result_h) > $this->rc->storage->get_pagesize())
            $result_h = array_slice($result_h, ($page - 1) * $this->rc->storage->get_pagesize(), $this->rc->storage->get_pagesize());

        return $result_h;
    }

    /**
     * Called when message statuses are modified or they have been flagged
     *
     * @access  public
     */
    function message_label_mark() {

        $a_flags_map = array(
            'undelete' => 'UNDELETED',
            'delete' => 'DELETED',
            'read' => 'SEEN',
            'unread' => 'UNSEEN',
            'flagged' => 'FLAGGED',
            'unflagged' => 'UNFLAGGED');

        if (($uids = get_input_value('_uid', RCUBE_INPUT_POST)) && ($flag = get_input_value('_flag', RCUBE_INPUT_POST))) {

            $flag = $a_flags_map[$flag] ? $a_flags_map[$flag] : strtoupper($flag);

            if ($flag == 'DELETED' && $this->rc->config->get('skip_deleted') && $_POST['_from'] != 'show') {
                // count messages before changing anything
                $old_count = count($_SESSION['label_folder_search']['uid_mboxes']);
                $old_pages = ceil($old_count / $this->rc->storage->get_pagesize());
                $count = sizeof(explode(',', $uids));
            }

            $uids = explode(',', $uids);

            // mark each uid individually because the mailboxes may differ
            foreach ($uids as $uid) {
                $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                $this->rc->storage->set_folder($mbox);
                $marked = $this->rc->storage->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], $flag);

                if (!$marked) {
                    // send error message
                    if ($_POST['_from'] != 'show')
                        $this->rc->output->command('list_mailbox');

                    $this->rc->output->show_message('errormarking', 'error');
                    $this->rc->output->send();
                    exit;
                }
                else if (empty($_POST['_quiet'])) {
                    $this->rc->output->show_message('messagemarked', 'confirmation');
                }
            }

            $skip_deleted = $this->rc->config->get('skip_deleted');
            $read_when_deleted = $this->rc->config->get('read_when_deleted');

            if ($flag == 'DELETED' && $read_when_deleted && !empty($_POST['_ruid'])) {
                $uids = get_input_value('_ruid', RCUBE_INPUT_POST);
                $uids = explode(',', $uids);

                foreach ($uids as $uid) {
                    $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                    $this->rc->storage->set_folder($mbox);
                    $read = $this->rc->storage->set_flag($_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'], 'SEEN');

                    if ($read != -1 && !$skip_deleted)
                        $this->rc->output->command('flag_deleted_as_read', $uid);
                }
            }

            if ($flag == 'SEEN' || $flag == 'UNSEEN' || ($flag == 'DELETED' && !$skip_deleted)) {
                // just update unread count for all mailboxes, easier than figuring out which were changed
                $mbox_names = $this->rc->storage->list_folders_subscribed();

                foreach ($mbox_names as $mbox)
                    $this->rc->output->command('set_unread_count', $mbox, $this->rc->storage->count($mbox, 'UNSEEN'), ($mbox == 'INBOX'));
            } else if ($flag == 'DELETED' && $skip_deleted) {
                if ($_POST['_from'] == 'show') {
                    if ($next = get_input_value('_next_uid', RCUBE_INPUT_GPC))
                        $this->rc->output->command('show_message', $next);
                    else
                        $this->rc->output->command('command', 'list');
                } else {
                    // refresh saved search set after moving some messages
                    if (($search_request = get_input_value('_search', RCUBE_INPUT_GPC)) && $_SESSION['label_folder_search']['uid_mboxes']) {
                        $_SESSION['search'][$search_request] = $this->perform_search($this->rc->storage->search_string);
                    }
                    $msg_count = count($_SESSION['label_folder_search']['uid_mboxes']);
                    $pages = ceil($msg_count / $this->rc->storage->get_pagesize());
                    $nextpage_count = $old_count - $this->rc->storage->get_pagesize() * $_SESSION['page'];
                    $remaining = $msg_count - $this->rc->storage->get_pagesize() * ($_SESSION['page'] - 1);
                    // jump back one page (user removed the whole last page)
                    if ($_SESSION['page'] > 1 && $nextpage_count <= 0 && $remaining == 0) {
                        $this->rc->storage->set_page($_SESSION['page'] - 1);
                        $_SESSION['page'] = $this->rc->storage->list_page;
                        $jump_back = true;
                    }
                    // update message count display
                    $this->rc->output->set_env('messagecount', $msg_count);
                    $this->rc->output->set_env('current_page', $this->rc->storage->list_page);
                    $this->rc->output->set_env('pagecount', $pages);
                    // update mailboxlist
                    foreach ($this->rc->storage->list_folders_subscribed() as $mbox) {
                        $unseen_count = $msg_count ? $this->rc->storage->count($mbox, 'UNSEEN') : 0;
                        $this->rc->output->command('set_unread_count', $mbox, $unseen_count, ($mbox == 'INBOX'));
                    }
                    $this->rc->output->command('set_rowcount', rcmail_get_messagecount_text($msg_count));

                    // add new rows from next page (if any)
                    if (($jump_back || $nextpage_count > 0)) {
                        $sort_col = isset($_SESSION['sort_col']) ? $_SESSION['sort_col'] : $this->rc->config->get('message_sort_col');
                        $sort_order = isset($_SESSION['sort_order']) ? $_SESSION['sort_order'] : $this->rc->config->get('message_sort_order');

                        $a_headers = $this->get_search_result();
                        $this->sort_search_result($a_headers);
                        $a_headers = array_slice($a_headers, $sort_order == 'DESC' ? 0 : -$count, $count);
                        rcmail_js_message_list($a_headers, false, false);
                    }
                }
            }
            $this->rc->output->send();
        }
        exit;
    }

    /**
     * Get message header results for the current all folder search
     *
     * @return  array    Indexed array with message header objects
     * @access  public
     */
    function get_search_result() {
        $result_h = array();

        foreach ($_SESSION['label_folder_search']['uid_mboxes'] as $id => $uid_mbox) {
            $uid = $uid_mbox['uid'];
            $mbox = $uid_mbox['mbox'];

            $result_h = $this->rc->storage->list_messages($mbox, 1, $_SESSION['sort_col'], $_SESSION['sort_order']);
            $row->uid = $id;
            array_push($result_h, $row);
        }
        return $result_h;
    }

    /**
     * Called when messages are moved
     *
     * @access  public
     */
    function message_label_move() {
        if (!empty($_POST['_uid']) && !empty($_POST['_target_mbox'])) {
            $uids = get_input_value('_uid', RCUBE_INPUT_POST);
            $target = get_input_value('_target_mbox', RCUBE_INPUT_POST);
            $uids = explode(',', $uids);

            foreach ($uids as $uid) {
                $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                $this->rc->storage->set_folder($mbox);
                $ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

                // flag messages as read before moving them
                if ($this->rc->config->get('read_when_deleted') && $target == $this->rc->config->get('trash_mbox'))
                    $this->rc->storage->set_flag($ruid, 'SEEN');

                $moved = $this->rc->storage->move_message($ruid, $target, $mbox);
            }

            if (!$moved) {
                // send error message
                if ($_POST['_from'] != 'show')
                    $this->rc->output->command('list_mailbox');

                $this->rc->output->show_message('errormoving', 'error');
                $this->rc->output->send();
                exit;
            }
            else {
                $this->rc->output->command('list_mailbox');
                $this->rc->output->show_message('messagemoved', 'confirmation');
                $this->rc->output->send();
                exit;
            }
        }
    }

    /**
     * Called when messages are delete
     *
     * @access  public
     */
    function message_label_delete() {
        if (!empty($_POST['_uid'])) {
            $uids = get_input_value('_uid', RCUBE_INPUT_POST);
            $uids = explode(',', $uids);

            foreach ($uids as $uid) {
                $mbox = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['mbox'];
                $this->rc->storage->set_folder($mbox);
                $ruid = $_SESSION['label_folder_search']['uid_mboxes'][$uid]['uid'];

                $del = $this->rc->storage->delete_message($ruid, $mbox);
            }

            if (!$del) {
                // send error message
                if ($_POST['_from'] != 'show')
                    $this->rc->output->command('list_mailbox');

                rcmail_display_server_error('errordeleting');
                $this->rc->output->send();
                exit;
            }
            else {
                $this->rc->output->command('list_mailbox');
                $this->rc->output->show_message('messagedeleted', 'confirmation');
                $this->rc->output->send();
                exit;
            }
        }
    }

    /**
     * Nullify label folder search results
     *
     * By nullifying we are letting the plugin know we are no longer in an label folder search
     *
     * @access  public
     */
    function not_label_folder_search() {
        $_SESSION['label_folder_search']['uid_mboxes'] = 0;
        $_SESSION['label_id'] = 0;
    }

    /**
     * Sort result header array by date, size, subject, or from using a bubble sort
     *
     * @param   array    Indexed array with message header objects
     * @access  private
     */
    private function sort_search_result(&$result_h) {
        // Bubble sort! <3333 (ideally sorting and page trimming should be done
        // in js but php has the convienent rcmail_js_message_list function
        for ($x = 0; $x < count($result_h); $x++) {
            for ($y = 0; $y < count($result_h); $y++) {
                // ick can a variable name be put into a variable so i can get this out of 2 loops
                switch ($_SESSION['sort_col']) {
                    case 'date': $first = $result_h[$x]->timestamp;
                        $second = $result_h[$y]->timestamp;
                        break;
                    case 'size': $first = $result_h[$x]->size;
                        $second = $result_h[$y]->size;
                        break;
                    case 'subject': $first = strtolower($result_h[$x]->subject);
                        $second = strtolower($result_h[$y]->subject);
                        break;
                    case 'from': $first = strtolower($result_h[$x]->from);
                        $second = strtolower($result_h[$y]->from);
                        break;
                }

                if ($first < $second) {
                    $hold = $result_h[$x];
                    $result_h[$x] = $result_h[$y];
                    $result_h[$y] = $hold;
                }
            }
        }

        if ($_SESSION['sort_order'] == 'DESC')
            $result_h = array_reverse($result_h);
    }

    /**
     * Redirect function on arrival label to folder list
     *
     * @access  public
     */
    function message_label_redirect() {
        $this->rc->output->redirect(array('_task' => 'settings', '_action' => 'label_preferences'));
    }

    /**
     * render labbel menu for markmessagemenu
     */
    function render_labels_menu($val) {
        if (isset($_SESSION['user_id'])) {
            $prefs = $this->rc->config->get('message_label', array());
            $input = new html_checkbox();
            if (count($prefs) > 0) {
                $attrib['class'] = 'labellistmenu';
                $ul .= html::tag('li', array('class' => 'separator_below'), $this->gettext('label_set'));

                foreach ($prefs as $p) {
                    $ul .= html::tag('li', null, html::a(
                                            array('class' => 'labellink active',
                                        'href' => '#',
                                        'onclick' => 'rcmail.label_messages(\'' . $p['id'] . '\')'), html::tag('span', array('class' => 'listmenu', 'style' => 'background-color:' . $p['color']), '') . $p['text']));
                }

                $out = html::tag('ul', $attrib, $ul, html::$common_attrib);
            }

            $this->rc->output->add_footer($out);
        }
    }

    /**
     * Label list to folder list menu and set flags in imap conn
     *
     * @access  public
     */
    function folder_list_label($args) {

        $args['content'] .= html::div(array('id' => 'mailboxlist-title', 'class' => 'boxtitle label_header_menu'), $this->gettext('label_title'));
        $prefs = $this->rc->config->get('message_label', array());

        if (!strlen($attrib['id']))
            $attrib['id'] = 'labellist';

        if (count($prefs) > 0) {
            $table = new html_table($attrib);
            foreach ($prefs as $p) {
                $table->add_row(array('id' => 'rcmrow' . $p['id']));
                $table->add(array('class' => 'labels_color'), html::tag('span', array('class' => 'lmessage', 'style' => 'background-color:' . $p['color']), ''));
                $table->add(array('class' => 'labels_name'), $p['text']);
            }
            $args['content'] .= html::div('lmenu', $table->show($attrib));
        } else {
            $args['content'] .= html::div('lmenu', html::a(array('href' => '#', 'onclick' => 'return rcmail.command(\'plugin.label_redirect\',\'true\',true)'), $this->gettext('label_create')));
        }

        $flags = array();
        foreach ($prefs as $prefs_val) {
            $flags += array(strtoupper($prefs_val['id']) => '$labels_' . $prefs_val['id']);
        }

        $this->rc->storage->conn->flags = array_merge($this->rc->storage->conn->flags, $flags);
        //write_log('debug', preg_replace('/\r\n$/', '', print_r($this->rc->storage->conn->flags,true)));

        // add id to message label table if not specified
        $this->rc->output->add_gui_object('labellist', $attrib['id']);

        return $args;
    }

    function flag_message_load($p) {
        $prefs = $this->rc->config->get('message_label', array());
        $flags = array();
        foreach ($prefs as $prefs_val) {
            $flags += array(strtoupper($prefs_val['id']) => '$labels_' . $prefs_val['id']);
            $flags += array(strtoupper('u' . $prefs_val['id']) => '$ulabels_' . $prefs_val['id']);
        }
        $this->rc->storage->conn->flags = array_merge($this->rc->storage->conn->flags, $flags);
    }

    /**
     * Display label configuratin in user preferences tab
     *
     * @access  public
     */
    function label_preferences($args) {
        if ($args['section'] == 'label_preferences') {

            $this->rc = rcmail::get_instance();
            $this->rc->storage_connect();

            $args['blocks']['create_label'] = array('options' => array(), 'name' => Q($this->gettext('label_create')));
            $args['blocks']['list_label'] = array('options' => array(), 'name' => Q($this->gettext('label_title')));

            $i = 0;
            $prefs = $this->rc->config->get('message_label', array());

            //insert empty row
            $args['blocks']['create_label']['options'][$i++] = array('title' => '', 'content' => $this->get_form_row());

            foreach ($prefs as $p) {
                $args['blocks']['list_label']['options'][$i++] = array(
                    'title' => '',
                    'content' => $this->get_form_row($p['id'], $p['header'], $p['folder'], $p['input'], $p['color'], $p['text'], true)
                );
            }
        }
        return($args);
    }

    /**
     * Create row label
     *
     * @access  private
     */
    private function get_form_row($id = '', $header = 'from', $folder = 'all', $input = '', $color = '#000000', $text = '', $delete = false) {

        $this->add_texts('localization');

        if (!$text)
            $text = Q($this->gettext('label_name'));
        //if (!$input) $input = Q($this->gettext('label_matches'));
        if (!$id)
            $id = uniqid();
        if (!$folder)
            $folder = 'all';

        // header select box
        $header_select = new html_select(array('name' => '_label_header[]', 'class' => 'label_header'));
        $header_select->add(Q($this->gettext('subject')), 'subject');
        $header_select->add(Q($this->gettext('from')), 'from');
        $header_select->add(Q($this->gettext('to')), 'to');
        $header_select->add(Q($this->gettext('cc')), 'cc');

        // folder search select
        $folder_search = new html_select(array('name' => '_folder_search[]', 'class' => 'folder_search'));

        $p = array('maxlength' => 100, 'realnames' => false);

        // get mailbox list
        $a_folders = $this->rc->storage->list_folders_subscribed();
        $delimiter = $this->rc->storage->get_hierarchy_delimiter();
        $a_mailboxes = array();

        foreach ($a_folders as $folder_list)
            rcmail_build_folder_tree($a_mailboxes, $folder_list, $delimiter);

        $folder_search->add(Q($this->gettext('label_all')), 'all');

        rcmail_render_folder_tree_select($a_mailboxes, $mbox, $p['maxlength'], $folder_search, $p['realnames']);

        // input field
        $search_info_text = $this->gettext('search_info');
        $input = new html_inputfield(array('name' => '_label_input[]', 'type' => 'text', 'autocomplete' => 'off', 'class' => 'watermark linput', 'value' => $input));
        $text = html::tag('input', array('name' => '_label_text[]', 'type' => 'text', 'title' => $search_info_text, 'class' => 'watermark linput', 'value' => $text));
        $select_color = html::tag('input', array('id' => $id, 'name' => '_label_color[]', 'type' => 'hidden', 'text' => 'hidden', 'class' => 'label_color_input', 'value' => $color));
        $id_field = html::tag('input', array('name' => '_label_id[]', 'type' => 'hidden', 'value' => $id));

        if (!$delete) {
            $button = html::a(array('href' => '#cc', 'class' => 'lhref', 'onclick' => 'return rcmail.command(\'save\',\'\',this)'), $this->gettext('add_row'));
        } else {
            $button = html::a(array('href' => '#cc', 'class' => 'lhref', 'onclick' => 'return rcmail.command(\'plugin.label_delete_row\',\'' . $id . '\')'), $this->gettext('delete_row'));
        }

        $content = $select_color .
                $text .
                $header_select->show($header) .
                $this->gettext('label_matches') .
                $input->show() .
                $id_field .
                $this->gettext('label_folder') .
                $folder_search->show($folder) .
                $button;

        return $content;
    }

    /**
     * Add a section label to the preferences section list
     *
     * @access  public
     */
    function preferences_section_list($args) {
        $args['list']['label_preferences'] = array(
            'id' => 'label_preferences',
            'section' => Q($this->gettext('label_title'))
        );
        return($args);
    }

    /**
     * Save preferences
     *
     * @access  public
     */
    function label_save($args) {
        if ($args['section'] != 'label_preferences')
            return;

        $rcmail = rcmail::get_instance();

        $id = get_input_value('_label_id', RCUBE_INPUT_POST);
        $header = get_input_value('_label_header', RCUBE_INPUT_POST);
        $folder = get_input_value('_folder_search', RCUBE_INPUT_POST);
        $input = get_input_value('_label_input', RCUBE_INPUT_POST);
        $color = get_input_value('_label_color', RCUBE_INPUT_POST);
        $text = get_input_value('_label_text', RCUBE_INPUT_POST);

        //write_log('debug', preg_replace('/\r\n$/', '', print_r($_POST,true)));

        for ($i = 0; $i < count($header); $i++) {
            if (!in_array($header[$i], array('subject', 'from', 'to', 'cc'))) {
                $rcmail->output->show_message('message_label.headererror', 'error');
                return;
            }
            if (!preg_match('/^#[0-9a-fA-F]{2,6}$/', $color[$i])) {
                $rcmail->output->show_message('message_label.invalidcolor', 'error');
                return;
            }
            if ($input[$i] == '') {
                continue;
            }
            $prefs[] = array('id' => $id[$i], 'header' => $header[$i], 'folder' => $folder[$i], 'input' => $input[$i], 'color' => $color[$i], 'text' => $text[$i]);
        }

        $args['prefs']['message_label'] = $prefs;
        return($args);
    }

    function action_check_mode() {
        $check = get_input_value('_check', RCUBE_INPUT_POST);
        $this->rc = rcmail::get_instance();

        $mode = $this->rc->config->get('message_label_mode');

        if ($check == 'highlighting') {
            $this->rc->user->save_prefs(array('message_label_mode' => 'highlighting'));
            $this->rc->output->command('display_message', $this->gettext('check_highlighting'), 'confirmation');
        } else if ($check == 'labels') {
            $this->rc->user->save_prefs(array('message_label_mode' => 'labels'));
            $this->rc->output->command('display_message', $this->gettext('check_labels'), 'confirmation');
        } else {
            $this->rc->output->command('display_message', $this->gettext('check_error'), 'error');
        }
        $this->rc->output->send();
    }

}

?>
