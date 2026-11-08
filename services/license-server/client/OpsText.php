<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Client;

require_once __DIR__ . '/Config.php';

/** Translate an exact template before inserting opaque data. */
final class OpsText
{
    private static ?string $language = null;
    private const VI = [
        'FluxFiles weekly report {week}' => 'Báo cáo tuần FluxFiles {week}',
        'Alert digest: {count} alerts; open the admin UI' =>
            'Tổng hợp cảnh báo: {count} cảnh báo khác; mở trang quản trị',
        ' (+{count} similar)' => ' (+{count} lần tương tự)',
        'Key reveal burst; inspect the audit log' =>
            'Truy cập khóa liên tục; kiểm tra nhật ký quản trị',
        'Admin test alert' => 'Thông báo thử từ quản trị',
        'New order · {plan} · {email} · {order}' => 'Đơn hàng mới · {plan} · {email} · {order}',
        'Order refunded · {order}' => 'Đã hoàn tiền đơn hàng · {order}',
        'Licence email bounced · {email}' => 'Thư giấy phép bị trả · {email}',
        'Mail failed · {email}' => 'Gửi thư thất bại · {email}',
        'Undelivered licence · {email}' => 'Giấy phép chưa giao · {email}',
        'Never installed · {email}' => 'Chưa ghi nhận cài đặt · {email}',
        'Paid renewal cancelled by cascade · {jti}' =>
            'Gia hạn đã trả tiền bị thu hồi theo chuỗi · {jti}',
        'Refunded order {order}; cancel subscription {subscription} in Polar' =>
            'Đơn hàng đã hoàn tiền {order}; hủy thuê bao {subscription} trong Polar',
        'Webhook signature failures' => 'Webhook sai chữ ký',
        'Webhook: {outcome}' => 'Webhook: {outcome}',
        'Webhook: {outcome} · {product}' => 'Webhook: {outcome} · {product}',
        'unmapped_product' => 'sản phẩm chưa ánh xạ',
        'renewal_unmatched' => 'không khớp giấy phép gia hạn',
        'mail_failed' => 'gửi thư thất bại',
        'error' => 'lỗi',
        'Health down · {target}' => 'Dịch vụ ngừng hoạt động · {target}',
        'Health recovered · {target}' => 'Dịch vụ hoạt động trở lại · {target}',
        'TLS expires · {target} · {days} days' => 'TLS sắp hết hạn · {target} · {days} ngày',
        'Disk space low · {path} · {percent}%' => 'Dung lượng trống thấp · {path} · {percent}%',
        'Backup missing or older than 26 hours' => 'Chưa có bản sao lưu hoặc đã quá 26 giờ',
        'Discount {code} reached {percent}% (refunds shown separately)' =>
            'Mã giảm giá {code} đã đạt {percent}% (hiện riêng lượt hoàn tiền)',
        'Week net: {net} {currency} minor units' =>
            'Doanh thu thuần tuần: {net} {currency} đơn vị nhỏ nhất',
        'Month-to-date net: {net} {currency} minor units; MRR {mrr}; change {change} since previous report' =>
            'Doanh thu thuần từ đầu tháng: {net} {currency} đơn vị nhỏ nhất; MRR {mrr}; thay đổi {change} so với báo cáo trước',
        'New customers: {count}; refunds: {refunds}' =>
            'Khách hàng mới: {count}; hoàn tiền: {refunds}',
        'Active installs: {count}; never installed: {never}' =>
            'Cài đặt đang dùng: {count}; chưa ghi nhận cài đặt: {never}',
        '{code}: {paid}/{cap}; refunded {refunded}' =>
            '{code}: {paid}/{cap}; đã hoàn tiền {refunded}',
        'Needs attention: {summary}' => 'Cần xử lý: {summary}',
        'Webhook failures (7d): {count}' => 'Webhook lỗi (7 ngày): {count}',
        'Mail (30d): {kind}; sent: {sent}; accepted: {accepted}; delivered: {delivered}; bounced: {bounced}; failed: {failed}' =>
            'Giao thư (30 ngày): {kind}; đã gửi: {sent}; đã tiếp nhận: {accepted}; đã giao: {delivered}; bị trả: {bounced}; thất bại: {failed}',
        'Backup last OK: {time}' => 'Sao lưu thành công gần nhất: {time}',
        'Health {target}: {status}; TLS days {days}' =>
            'Dịch vụ {target}: {status}; số ngày TLS còn lại {days}',
        'Weekly {week}' => 'Báo cáo tuần {week}',
        '{currency} net {net}' => '{currency} thuần {net}',
        'Active installs: {count}' => 'Cài đặt đang dùng: {count}',
        '{currency} net: {net} minor units; MRR: {mrr}' =>
            '{currency} thuần: {net} đơn vị nhỏ nhất; MRR: {mrr}',
        'orders: {count}' => 'đơn hàng: {count}',
        'refunds: {count}' => 'hoàn tiền: {count}',
        'failures: {count}' => 'lỗi: {count}',
        'alerts: {count}' => 'cảnh báo: {count}',
        'missing' => 'chưa có',
        'unknown' => 'chưa rõ',
        'DOWN' => 'ngừng hoạt động',
    ];

    public static function language(): string
    {
        return self::$language ??
            (Config::get('FLUXFILES_ADMIN_LANG', 'vi') === 'en' ? 'en' : 'vi');
    }

    // The keyless worker takes the API's language, including for suppression suffixes.
    public static function useLanguage(string $language): void
    {
        self::$language = $language === 'en' ? 'en' : 'vi';
    }

    public static function text(string $template, array $arguments = []): string
    {
        $template = self::language() === 'vi' ? self::VI[$template] ?? $template : $template;
        $values = [];
        foreach ($arguments as $key => $value) {
            $values['{' . $key . '}'] = (string) $value;
        }
        return strtr($template, $values);
    }
}
