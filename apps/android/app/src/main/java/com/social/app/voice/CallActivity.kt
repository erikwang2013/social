// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
package com.social.app.voice

import android.os.Bundle
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.social.app.im.WsClient
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import kotlinx.serialization.json.put

/**
 * 1v1 语音通话：WS call_* 信令处理 + 系统通话 UI 骨架。
 * 媒体面（WebRTC offer/answer/ICE）不在本里程碑实现。
 */
class CallActivity : AppCompatActivity() {
    private lateinit var status: TextView
    private lateinit var acceptBtn: Button
    private lateinit var rejectBtn: Button
    private lateinit var hangupBtn: Button
    private var peerId = 0L
    private var inCall = false
    private var prevHandler: ((String, JsonObject) -> Unit)? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val ll = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        status = TextView(this).apply { text = "等待来电…" }
        acceptBtn = Button(this).apply { text = "接听" }
        rejectBtn = Button(this).apply { text = "拒绝" }
        hangupBtn = Button(this).apply { text = "挂断" }
        acceptBtn.isEnabled = false
        rejectBtn.isEnabled = false
        hangupBtn.isEnabled = false
        ll.addView(status); ll.addView(acceptBtn); ll.addView(rejectBtn); ll.addView(hangupBtn)
        setContentView(ll)

        acceptBtn.setOnClickListener {
            WsClient.send("call_accept", buildJsonObject { put("peer_id", peerId) })
            inCall = true
            setCallingUi()
        }
        rejectBtn.setOnClickListener {
            WsClient.send("call_reject", buildJsonObject { put("peer_id", peerId) })
            status.text = "已拒绝"
            inCall = false
            setIdleUi()
        }
        hangupBtn.setOnClickListener {
            WsClient.send("call_hangup", buildJsonObject { put("peer_id", peerId) })
            status.text = "已挂断"
            inCall = false
            setIdleUi()
        }

        prevHandler = WsClient.onEvent
        WsClient.onEvent = { type, data -> runOnUiThread { handle(type, data) } }
    }

    private fun handle(type: String, data: JsonObject) {
        when (type) {
            "call_invite" -> {
                peerId = data["caller_id"]?.jsonPrimitive?.long ?: 0L
                status.text = "来电 用户#$peerId"
                inCall = false
                acceptBtn.isEnabled = true
                rejectBtn.isEnabled = true
                hangupBtn.isEnabled = false
            }
            "call_accept" -> {
                inCall = true
                setCallingUi()
            }
            "call_cancel" -> { status.text = "对方已取消"; setIdleUi() }
            "call_reject" -> { status.text = "对方已拒绝"; setIdleUi() }
            "call_hangup" -> { status.text = "通话已结束"; setIdleUi() }
            "call_failed" -> { status.text = "通话建立失败"; setIdleUi() }
            "call_timeout" -> { status.text = "呼叫超时"; setIdleUi() }
            "call_offer", "call_answer", "call_ice" -> {
                // TODO 真机联调：WebRTC 媒体面——offer/answer/ICE 信令透传 SFU，本里程碑不实现
                status.text = "已收到 $type 信令（WebRTC TODO）"
            }
        }
    }

    private fun setCallingUi() {
        status.text = "通话中 用户#$peerId（WebRTC TODO）"
        acceptBtn.isEnabled = false
        rejectBtn.isEnabled = false
        hangupBtn.isEnabled = true
    }

    private fun setIdleUi() {
        acceptBtn.isEnabled = false
        rejectBtn.isEnabled = false
        hangupBtn.isEnabled = false
    }

    override fun onDestroy() {
        WsClient.onEvent = prevHandler // 还原 IM 客户端的 onEvent，避免串台
        super.onDestroy()
    }
}
