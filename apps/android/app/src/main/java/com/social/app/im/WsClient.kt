package com.social.app.im

import com.social.app.api.ApiClient
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread

object WsClient {
    private const val MAX_DELAY_MS = 30_000L
    private val json = Json { ignoreUnknownKeys = true }
    private var ws: WebSocket? = null
    private var host = ""
    private var lastToken = ""
    private var reconnectDelayMs = 1_000L
    private val pending = mutableMapOf<String, JsonObject>() // client_msg_id -> 未 ack 的 send 帧

    var onEvent: ((type: String, data: JsonObject) -> Unit)? = null

    fun connect(host: String, token: String) {
        this.host = host
        if (token != lastToken) pending.clear() // 换用户不重发旧 pending
        lastToken = token
        ws?.cancel()
        val req = Request.Builder().url("ws://$host:8789?token=$token").build()
        val client = OkHttpClient.Builder().pingInterval(30, TimeUnit.SECONDS).build()
        ws = client.newWebSocket(req, object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                reconnectDelayMs = 1_000L
                pending.values.forEach { webSocket.send(it.toString()) }
            }

            override fun onMessage(webSocket: WebSocket, text: String) {
                val root = json.parseToJsonElement(text).jsonObject
                val type = root["type"]?.jsonPrimitive?.content ?: return
                val data = root["data"]?.jsonObject ?: JsonObject(emptyMap())
                if (type == "ack") {
                    data["client_msg_id"]?.jsonPrimitive?.content?.let { pending.remove(it) }
                }
                onEvent?.invoke(type, data)
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) = scheduleReconnect()

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) = scheduleReconnect()
        })
    }

    fun send(type: String, data: JsonObject) {
        val frame = buildJsonObject { put("type", type); put("data", data) }
        if (type == "send") {
            data["client_msg_id"]?.jsonPrimitive?.content?.let { pending[it] = frame }
        }
        ws?.send(frame.toString())
    }

    private fun scheduleReconnect() {
        thread {
            Thread.sleep(reconnectDelayMs + (Math.random() * reconnectDelayMs).toLong())
            reconnectDelayMs = (reconnectDelayMs * 2).coerceAtMost(MAX_DELAY_MS)
            val token = ApiClient.accessToken
            if (host.isNotEmpty() && token.isNotEmpty()) connect(host, token)
        }
    }
}
