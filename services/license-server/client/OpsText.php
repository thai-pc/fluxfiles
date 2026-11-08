<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Client;

require_once __DIR__.'/Config.php';

/** Small operational catalogue shared by the API and keyless worker. */
final class OpsText
{
    public static function text(string $message): string
    {
        if (Config::get('FLUXFILES_ADMIN_LANG','vi')==='en') { return $message; }
        return strtr($message,[
            'FluxFiles weekly report '=>'Báo cáo tuần FluxFiles ',
            'Alert digest: '=>'Tổng hợp cảnh báo: ', ' alerts; open the admin UI'=>' cảnh báo khác; mở trang quản trị',
            ' similar)'=>' lần tương tự)',
            'Key reveal burst; inspect the audit log'=>'Truy cập khóa liên tục; kiểm tra nhật ký quản trị',
            'Admin test alert'=>'Thông báo thử từ quản trị',
            'New order · '=>'Đơn hàng mới · ', 'Order refunded · '=>'Đã hoàn tiền đơn hàng · ',
            'Licence email bounced · '=>'Thư giấy phép bị trả · ', 'Mail failed · '=>'Gửi thư thất bại · ',
            'Undelivered licence · '=>'Giấy phép chưa giao · ', 'Never installed · '=>'Chưa ghi nhận cài đặt · ',
            'Paid renewal cancelled by cascade · '=>'Gia hạn đã trả tiền bị thu hồi theo chuỗi · ',
            'Refunded order '=>'Đơn hàng đã hoàn tiền ', '; cancel subscription '=>'; hủy thuê bao ', ' in Polar'=>' trong Polar',
            'Webhook signature failures'=>'Webhook sai chữ ký', 'unmapped_product'=>'sản phẩm chưa ánh xạ',
            'renewal_unmatched'=>'không khớp giấy phép gia hạn', 'mail_failed'=>'gửi thư thất bại',
            'Health down · '=>'Dịch vụ ngừng hoạt động · ', 'Health recovered · '=>'Dịch vụ hoạt động trở lại · ',
            'TLS expires · '=>'TLS sắp hết hạn · ', ' days'=>' ngày',
            'Disk space low · '=>'Dung lượng trống thấp · ',
            'Backup missing or older than 26 hours'=>'Chưa có bản sao lưu hoặc đã quá 26 giờ',
            'Discount '=>'Mã giảm giá ', ' reached '=>' đã đạt ', '% (refunds shown separately)'=>'% (hiện riêng lượt hoàn tiền)',
            'Week net: '=>'Doanh thu thuần tuần: ', 'Month-to-date net: '=>'Doanh thu thuần từ đầu tháng: ',
            ' minor units'=>' đơn vị nhỏ nhất', '; change '=>'; thay đổi ', ' since previous report'=>' so với báo cáo trước',
            'New customers: '=>'Khách hàng mới: ', '; refunds: '=>'; hoàn tiền: ',
            'Active installs: '=>'Cài đặt đang dùng: ', '; never installed: '=>'; chưa ghi nhận cài đặt: ',
            '; refunded '=>'; đã hoàn tiền ', 'Needs attention: '=>'Cần xử lý: ',
            'Webhook failures (7d): '=>'Webhook lỗi (7 ngày): ', 'Mail (30d): '=>'Giao thư (30 ngày): ',
            'Backup last OK: '=>'Sao lưu thành công gần nhất: ', 'Health '=>'Dịch vụ ', '; TLS days '=>'; số ngày TLS còn lại ',
            '; sent: '=>'; đã gửi: ', '; accepted: '=>'; đã tiếp nhận: ', '; delivered: '=>'; đã giao: ', '; bounced: '=>'; bị trả: ', '; failed: '=>'; thất bại: ', ' net '=>' thuần ',
            'Weekly '=>'Báo cáo tuần ', 'missing'=>'chưa có', 'unknown'=>'chưa rõ', 'DOWN'=>'ngừng hoạt động',
            'orders: '=>'đơn hàng: ', 'refunds: '=>'hoàn tiền: ', 'failures: '=>'lỗi: ', 'alerts: '=>'cảnh báo: ',
        ]);
    }
}
