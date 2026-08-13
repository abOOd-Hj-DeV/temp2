<?php
// app/Services/UltraMsg/UltraMsgService.php

namespace App\Services\UltraMsg;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable; // للتأكد من التقاط جميع الأخطاء

class UltraMsgService
{
    private Client $client;
    private string $instanceId;
    private string $token;
    private string $baseUrl; // الرابط الأساسي (https://api.ultramsg.com/)

    public function __construct()
    {
        // 1. جلب المتغيرات من .env
        $this->instanceId = env('ULTRAMSG_INSTANCE_ID');
        $this->token = env('ULTRAMSG_TOKEN');

        // 2. تعيين الرابط الأساسي بدون أي Endpoints
        $this->baseUrl = env('ULTRAMSG_BASE_URL');

        // 3. تهيئة Guzzle مع مهلة زمنية
        $this->client = new Client([
            // نستخدم الرابط الأساسي في Guzzle
            'base_uri' => $this->baseUrl,
            'timeout' => 10.0, // زيادة المهلة قليلاً
        ]);
    }

    /**
     * إرسال رسالة نصية عبر UltraMsg API
     */
    public function sendWhatsAppMessage(string $to, string $body): bool
    {
        // بناء الرابط المخصص للرسائل
        // يجب أن يكون المسار النسبي: /instance155344/messages/chat
        $endpoint = '/' . $this->instanceId . '/messages/chat';

        try {
            $response = $this->client->post($endpoint, [
                'form_params' => [
                    'token' => $this->token, // ⬅️ استخدام خاصية الـ Token
                    'to' => $to,
                    'body' => $body,
                ],
                // يجب التأكد من عدم وجود رأسيات (Headers) متضاربة إذا كانت UltraMsg لا تتطلبها
            ]);

            // التحقق من رمز الحالة
            if ($response->getStatusCode() !== 200) {
                Log::error('UltraMsg Failed: Bad Status Code.', ['status' => $response->getStatusCode(), 'response' => $response->getBody()->getContents()]);
                return false;
            }

            $result = json_decode($response->getBody()->getContents(), true);

            // التحقق من نجاح الاستجابة
            if (isset($result['sent']) || (isset($result['status']) && $result['status'] === 'queued')) {
                return true;
            }

            // تسجيل أي استجابة فشل أخرى من UltraMsg
            Log::error('UltraMsg Failed: API Error Response.', ['response' => $result]);
            return false;

        } catch (Throwable $e) {
            // تسجيل أي خطأ اتصال (Network, Timeout, 401, 404, etc.)
            Log::error("UltraMsg connection failed: " . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }
}
