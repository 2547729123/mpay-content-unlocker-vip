<?php
/*
Plugin Name: Mpay支付自动解锁 + B2自动开通VIP会员（多等级）
Description: 使用Mpay聚合支付，支持自定义vip等级支付解锁。短代码例：[mpay_unlock price="39.9" name="VIP购买" vip="vip1"]隐藏内容[/mpay_unlock]
Version: 1.4
Author: 码铃薯
*/

// 公共函数：生成支付链接
function generate_pay_url($type, $pid, $key, $order_id, $notify_url, $return_url, $name, $price) {
    $data = [
        'pid' => $pid,
        'type' => $type,
        'out_trade_no' => $order_id . "_$type",
        'notify_url' => $notify_url,
        'return_url' => $return_url,
        'name' => $name,
        'money' => $price,
        'sign_type' => 'MD5'
    ];
    ksort($data);
    $sign_str = '';
    foreach ($data as $k => $v) {
        if ($k !== 'sign' && $k !== 'sign_type' && $v !== '') {
            $sign_str .= "$k=$v&";
        }
    }
    $sign_str = rtrim($sign_str, '&') . $key;
    $data['sign'] = md5($sign_str);

    return 'https://mpay.52yzk.com/submit.php?' . http_build_query($data);
}

// 动态meta
add_action('wp_head', 'mpay_dynamic_meta_description');
function mpay_dynamic_meta_description() {
    if (is_singular()) {
        global $post;
        $post_id = $post->ID;
        $cookie_alipay = isset($_COOKIE['mpay_unlocked_alipay_' . $post_id]) && $_COOKIE['mpay_unlocked_alipay_' . $post_id] === '1';
        $cookie_wx = isset($_COOKIE['mpay_unlocked_wx_' . $post_id]) && $_COOKIE['mpay_unlocked_wx_' . $post_id] === '1';
        $unlocked = $cookie_alipay || $cookie_wx;
        $description = $unlocked ? get_the_excerpt($post_id) : '支付解锁内容，点击支付查看。';
        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
    }
}

// 核心短代码
add_shortcode('mpay_unlock', function($atts, $content = null) {
    if (!is_singular()) return '';
    global $post;
    $post_id = $post->ID;

    $atts = shortcode_atts([
        'price' => '29.9',
        'name'  => get_the_title($post_id),
        'vip'   => 'vip3'
    ], $atts);

    $cookie_alipay = isset($_COOKIE['mpay_unlocked_alipay_' . $post_id]) && $_COOKIE['mpay_unlocked_alipay_' . $post_id] === '1';
    $cookie_wx = isset($_COOKIE['mpay_unlocked_wx_' . $post_id]) && $_COOKIE['mpay_unlocked_wx_' . $post_id] === '1';
    $unlocked = $cookie_alipay || $cookie_wx;

    $pid = '1000';
    $key = '3056f64b12e509c2948e97965570bc92';
    $order_id = 'order_' . time() . rand(1000, 9999);
    $notify_url = site_url('/mpay-notify');
    $return_url = site_url('/mpay-return?post_id=' . $post_id . '&vip=' . $atts['vip'] . '&out_trade_no=' . $order_id);

    $alipay_url = generate_pay_url('alipay', $pid, $key, $order_id, $notify_url, $return_url, $atts['name'], $atts['price']);
    $wxpay_url = generate_pay_url('wxpay', $pid, $key, $order_id, $notify_url, $return_url, $atts['name'], $atts['price']);

    ob_start();
    ?>
    <div id="mpay-container-<?php echo $post_id; ?>">
        <?php if (!$unlocked): ?>
            <div style="border:1px solid #ccc;padding:15px;background:#fff9f9;">
                <p><strong>✨ 限时活动特价 <?php echo esc_html($atts['price']); ?> 元！</strong></p>
                <a href="<?php echo esc_url($alipay_url); ?>" target="_blank"><img src="/wp-content/plugins/mpay-content-unlocker/img/alipay.jpg" width="160"></a>
                <a href="<?php echo esc_url($wxpay_url); ?>" target="_blank"><img src="/wp-content/plugins/mpay-content-unlocker/img/wxpay.jpg" width="160"></a>
                <p style="font-size:13px;color:#888;margin-top:10px;">📌 支付完成后自动开通会员并跳转会员页面</p>
                <script>
function triggerB2Chat(){
    const btn = document.querySelector('.bar-footer .bar-item');
    if(btn){
        btn.click();
    }else{
        alert('客服按钮未找到，可能主题结构更新了');
    }
}
</script>

<p style="font-size:13px;margin-top:10px;">
  💡 如遇未开通问题，请联系
  <span style="color: #ff00ff; cursor: pointer;" onclick="triggerB2Chat()">
    <i class="b2font b2-customer-service-2-line1" style="font-size:14px;margin-right:2px;"></i>
    客服（网站右下角也有客服）
  </span>
  <span style="color: #0000ff;">
    （点击图标留言你的问题，站长会帮你处理的）
  </span>
</p>

            </div>
        <?php endif; ?>
        <div id="mpay-unlock-content-<?php echo $post_id; ?>"></div>
    </div>

    <script>
    (function(){
        const unlocked = <?php echo $unlocked ? 'true' : 'false'; ?>;
        if (unlocked) {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mpay_get_content&post_id=<?php echo $post_id; ?>'
            }).then(response => response.text()).then(html => {
                document.getElementById('mpay-unlock-content-<?php echo $post_id; ?>').innerHTML = html;
            });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
});

// 支付回调
add_action('init', function () {
    if (strpos($_SERVER['REQUEST_URI'], '/mpay-return') !== false && isset($_GET['post_id']) && isset($_GET['out_trade_no'])) {
        $post_id = intval($_GET['post_id']);
        $out_trade_no = $_GET['out_trade_no'];
        $vip_level = sanitize_text_field($_GET['vip'] ?? 'vip3');

        if (strpos($out_trade_no, 'alipay') !== false) {
            setcookie('mpay_unlocked_alipay_' . $post_id, '1', time() + 86400, '/');
            $_COOKIE['mpay_unlocked_alipay_' . $post_id] = '1';
        } elseif (strpos($out_trade_no, 'wxpay') !== false) {
            setcookie('mpay_unlocked_wx_' . $post_id, '1', time() + 86400, '/');
            $_COOKIE['mpay_unlocked_wx_' . $post_id] = '1';
        }

        // 自动开通VIP
        $user_id = get_current_user_id();
        if ($user_id && class_exists('B2\Modules\Common\Orders')) {
            $data = [
                'user_id' => $user_id,
                'order_key' => $vip_level
            ];
            \B2\Modules\Common\Orders::callback_vip($data);
        }

        echo '<p style="font-family:sans-serif;padding:2em;">✅ 支付成功，正在跳转到会员页面...</p>';
        echo '<script>
            setTimeout(function() {
                window.location.href = "' . site_url('/vips') . '";
            }, 1000);
        </script>';
        exit;
    }
});

// 异步通知接口
add_action('init', function () {
    if (strpos($_SERVER['REQUEST_URI'], '/mpay-notify') !== false) {
        $key = '3056f64b12e509c2948e97965570bc92';
        $data = $_GET;
        $sign = $data['sign'] ?? '';
        unset($data['sign'], $data['sign_type']);
        ksort($data);
        $sign_str = http_build_query($data) . $key;
        $verify_sign = md5($sign_str);
        echo ($verify_sign === $sign && ($data['trade_status'] ?? '') === 'TRADE_SUCCESS') ? 'success' : 'fail';
        exit;
    }
});

// 可视化按钮
add_action('media_buttons', 'mpay_add_shortcode_button', 15);
function mpay_add_shortcode_button() {
    echo '<a href="#" id="insert-mpay-shortcode" class="button">插入支付解锁短代码</a>
    <script>
        jQuery(document).ready(function($){
            $("#insert-mpay-shortcode").click(function(e){
                e.preventDefault();
                send_to_editor(\'[mpay_unlock price="39.9" name="VIP购买" vip="vip3"]这里是隐藏内容[/mpay_unlock]\');
            });
        });
    </script>';
}

// 禁止摘要中执行短代码
add_action('init', function() {
    remove_filter('the_excerpt', 'do_shortcode');
});

// 解锁内容的AJAX接口
add_action('wp_ajax_mpay_get_content', 'mpay_ajax_get_content');
add_action('wp_ajax_nopriv_mpay_get_content', 'mpay_ajax_get_content');

function mpay_ajax_get_content() {
    $post_id = intval($_POST['post_id']);
    if (!$post_id || !get_post($post_id)) wp_send_json_error('非法请求');

    $alipay_cookie = isset($_COOKIE['mpay_unlocked_alipay_' . $post_id]) && $_COOKIE['mpay_unlocked_alipay_' . $post_id] === '1';
    $wx_cookie = isset($_COOKIE['mpay_unlocked_wx_' . $post_id]) && $_COOKIE['mpay_unlocked_wx_' . $post_id] === '1';

    if (!$alipay_cookie && !$wx_cookie) wp_send_json_error('未授权');

    $post = get_post($post_id);
    $pattern = get_shortcode_regex(['mpay_unlock']);
    if (preg_match_all('/' . $pattern . '/s', $post->post_content, $matches) && isset($matches[2], $matches[5])) {
        foreach ($matches[2] as $i => $shortcode_name) {
            if ($shortcode_name === 'mpay_unlock') {
                echo do_shortcode($matches[5][$i]);
            }
        }
    }

    wp_die();
}