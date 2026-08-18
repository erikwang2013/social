// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
package com.social.app.voice

import android.media.AudioAttributes
import android.media.MediaPlayer
import android.net.Uri
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import com.social.app.api.ApiClient

/**
 * 语音消息列表项。点击播放；GET /api/v1/voice/{file} 需要鉴权，
 * 必须通过 headers 带上 Authorization + X-Api-Version，不能裸 URL 播放。
 */
object VoiceMessageView {
    fun show(parent: LinearLayout, base: String, voiceUrl: String, duration: Int) {
        val row = LinearLayout(parent.context).apply { orientation = LinearLayout.HORIZONTAL }
        val player = Button(parent.context).apply {
            text = if (duration > 0) "语音 ${duration}s" else "语音"
        }
        val state = TextView(parent.context)
        player.setOnClickListener {
            val headers = mapOf(
                "Authorization" to "Bearer ${ApiClient.accessToken}",
                "X-Api-Version" to VoiceApi.API_VERSION,
            )
            state.text = "播放中…"
            try {
                val mp = MediaPlayer()
                mp.setAudioAttributes(
                    AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_MEDIA)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                        .build()
                )
                mp.setDataSource(parent.context, Uri.parse(base + voiceUrl), headers)
                mp.setOnPreparedListener { it.start() }
                mp.setOnCompletionListener { it.release(); state.text = "" }
                mp.setOnErrorListener { _, _, _ -> state.text = "播放失败"; true }
                mp.prepareAsync()
            } catch (e: Exception) {
                state.text = "播放失败"
            }
        }
        row.addView(player); row.addView(state)
        parent.addView(row)
    }
}
