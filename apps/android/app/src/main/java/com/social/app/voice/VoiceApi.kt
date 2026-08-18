// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
package com.social.app.voice

import com.social.app.api.ApiClient
import java.io.ByteArrayOutputStream
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject

/**
 * 语音功能 HTTP API。所有请求携带 Authorization + X-Api-Version 头。
 * 语音文件播放 GET /api/v1/voice/{file} 同样需要这两个头，客户端必须带头播放。
 */
class VoiceApi(
    private val base: String = BASE,
    private val token: String = ApiClient.accessToken,
) {
    companion object {
        const val API_VERSION = "v1"
        const val BASE = "http://10.0.2.2:8788" // 模拟器访问宿主机
    }

    private val json = Json { ignoreUnknownKeys = true }

    /** 通话历史：GET /api/v1/voice/calls?page= */
    fun calls(page: Int = 1): JsonObject = request("GET", "/api/v1/voice/calls?page=$page")

    /** 创建语聊房：POST /api/v1/voice/rooms {name} */
    fun createRoom(name: String): JsonObject = request("POST", "/api/v1/voice/rooms", mapOf("name" to name))

    /** 开放房间列表：GET /api/v1/voice/rooms?page= */
    fun rooms(page: Int = 1): JsonObject = request("GET", "/api/v1/voice/rooms?page=$page")

    /** 上传语音：multipart field=voice → data.voice_url / data.voice_duration */
    fun uploadVoice(file: File): JsonObject {
        val boundary = "----SocialBoundary" + System.currentTimeMillis()
        val body = ByteArrayOutputStream().apply {
            write("--$boundary\r\n".toByteArray())
            write("Content-Disposition: form-data; name=\"voice\"; filename=\"voice.m4a\"\r\n".toByteArray())
            write("Content-Type: audio/mp4\r\n\r\n".toByteArray())
            write(file.readBytes())
            write("\r\n--$boundary--\r\n".toByteArray())
        }.toByteArray()
        val conn = open("POST", "/api/v1/im/voice")
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "multipart/form-data; boundary=$boundary")
        conn.outputStream.use { it.write(body) }
        return parse(read(conn))
    }

    /** 拼出可带鉴权头播放的完整 URL（base + /api/v1/voice/{file} 形如 voice_url 的值） */
    fun resolve(url: String): String = base + url

    private fun request(method: String, path: String, form: Map<String, String>? = null): JsonObject {
        val conn = open(method, path)
        if (form != null) {
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
            conn.outputStream.use { it.write(form.encodeUrlForm().toByteArray()) }
        }
        return parse(read(conn))
    }

    private fun open(method: String, path: String): HttpURLConnection {
        val conn = URL(base + path).openConnection() as HttpURLConnection
        conn.requestMethod = method
        conn.connectTimeout = 10_000
        conn.readTimeout = 15_000
        conn.setRequestProperty("X-Api-Version", API_VERSION)
        if (token.isNotEmpty()) conn.setRequestProperty("Authorization", "Bearer $token")
        return conn
    }

    private fun Map<String, String>.encodeUrlForm(): String =
        entries.joinToString("&") {
            URLEncoder.encode(it.key, "UTF-8") + "=" + URLEncoder.encode(it.value, "UTF-8")
        }

    /** 按状态码读 inputStream 或 errorStream，拿到错误体而非抛异常 */
    private fun read(conn: HttpURLConnection): String =
        try {
            val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
            stream?.bufferedReader()?.use { it.readText() }.orEmpty()
        } finally {
            conn.disconnect()
        }

    private fun parse(body: String): JsonObject = json.parseToJsonElement(body).jsonObject
}
