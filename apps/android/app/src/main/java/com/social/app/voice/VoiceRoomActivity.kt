// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
package com.social.app.voice

import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.social.app.im.WsClient
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import kotlinx.serialization.json.put

/**
 * 语聊房：开放房列表 + 麦位 UI 骨架 + WS room_* 信令处理。
 * 媒体面（WebRTC room_offer/answer/ice 透传 SFU）不在本里程碑实现。
 */
class VoiceRoomActivity : AppCompatActivity() {
    private lateinit var status: TextView
    private lateinit var roomList: LinearLayout
    private lateinit var micBtn: Button
    private lateinit var leaveBtn: Button
    private var roomId = 0L
    private var micOn = false
    private var prevHandler: ((String, JsonObject) -> Unit)? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val ll = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        val nameInput = EditText(this).apply { hint = "房间名" }
        val createBtn = Button(this).apply { text = "创建房间" }
        val refreshBtn = Button(this).apply { text = "刷新列表" }
        status = TextView(this).apply { text = "房间列表" }
        roomList = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        micBtn = Button(this).apply { text = "上麦" }
        leaveBtn = Button(this).apply { text = "离开房间" }
        micBtn.isEnabled = false
        leaveBtn.isEnabled = false
        ll.addView(nameInput); ll.addView(createBtn); ll.addView(refreshBtn)
        ll.addView(status); ll.addView(roomList); ll.addView(micBtn); ll.addView(leaveBtn)
        setContentView(ll)

        createBtn.setOnClickListener {
            val name = nameInput.text.toString()
            if (name.isBlank()) { status.text = "请输入房间名"; return@setOnClickListener }
            Thread {
                try {
                    val root = VoiceApi().createRoom(name)
                    runOnUiThread {
                        val id = root["data"]?.jsonObject?.get("room_id")?.jsonPrimitive?.long
                        status.text = if (id != null) "已创建房间 #$id" else "创建失败"
                        refreshRooms()
                    }
                } catch (e: Exception) {
                    runOnUiThread { status.text = "创建失败" }
                }
            }.start()
        }
        refreshBtn.setOnClickListener { refreshRooms() }
        micBtn.setOnClickListener {
            micOn = !micOn
            WsClient.send(if (micOn) "room_up_mic" else "room_down_mic", buildJsonObject { put("room_id", roomId) })
            micBtn.text = if (micOn) "下麦" else "上麦"
        }
        leaveBtn.setOnClickListener {
            WsClient.send("room_leave", buildJsonObject { put("room_id", roomId) })
            roomId = 0
            micBtn.isEnabled = false
            leaveBtn.isEnabled = false
            status.text = "已离开房间"
        }

        prevHandler = WsClient.onEvent
        WsClient.onEvent = { type, data -> runOnUiThread { handle(type, data) } }
        refreshRooms()
    }

    private fun refreshRooms() {
        Thread {
            try {
                val list = VoiceApi().rooms()["data"]!!.jsonObject["list"]!!.jsonArray
                runOnUiThread {
                    roomList.removeAllViews()
                    for (el in list) {
                        val r = el.jsonObject
                        val id = r["id"]!!.jsonPrimitive.long
                        val name = r["name"]!!.jsonPrimitive.content
                        val online = r["online_count"]?.jsonPrimitive?.int ?: 0
                        val mic = r["mic_count"]?.jsonPrimitive?.int ?: 0
                        val btn = Button(this).apply { text = "$name  #$id  在线$online 麦$mic" }
                        btn.setOnClickListener { joinRoom(id) }
                        roomList.addView(btn)
                    }
                    status.text = "房间列表（点击加入）"
                }
            } catch (e: Exception) {
                runOnUiThread { status.text = "房间加载失败" }
            }
        }.start()
    }

    private fun joinRoom(id: Long) {
        roomId = id
        WsClient.send("room_join", buildJsonObject { put("room_id", id) })
        status.text = "已加入房间 #$id"
        micBtn.isEnabled = true
        leaveBtn.isEnabled = true
    }

    private fun handle(type: String, data: JsonObject) {
        when (type) {
            "room_join" -> status.text = "有人加入房间 #$roomId"
            "room_leave" -> status.text = "有人离开房间 #$roomId"
            "room_up_mic" -> status.text = "有人上麦"
            "room_down_mic" -> status.text = "有人下麦"
            "room_kick_mic" -> status.text = "你被房主抱下麦"
            "room_closed" -> {
                status.text = "房间已关闭"
                micBtn.isEnabled = false
                leaveBtn.isEnabled = false
            }
            "room_offer", "room_answer", "room_ice" -> {
                // TODO 真机联调：WebRTC 媒体面——room_offer/answer/ice 信令透传 SFU，本里程碑不实现
                status.text = "已收到 $type 信令（WebRTC TODO）"
            }
        }
    }

    override fun onDestroy() {
        WsClient.onEvent = prevHandler // 还原 IM 客户端的 onEvent，避免串台
        super.onDestroy()
    }
}
