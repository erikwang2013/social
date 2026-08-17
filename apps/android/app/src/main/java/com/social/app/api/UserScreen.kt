package com.social.app.api

import android.app.Activity
import android.widget.*
import java.util.concurrent.Executors

object UserScreen {
    fun show(activity: Activity) {
        val ll = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
        val name = TextView(activity).apply { textSize = 20f }
        val bio = TextView(activity)
        val counts = TextView(activity)
        val list = ListView(activity)
        val executor = Executors.newSingleThreadExecutor()

        fun refresh() {
            executor.execute {
                val (uid, profile) = ApiClient.me()
                val rel = ApiClient.relation(uid)
                val posts = ApiClient.timeline()
                activity.runOnUiThread {
                    name.text = profile.nickname.ifEmpty { "未设置昵称" }
                    bio.text = profile.bio
                    counts.text = "粉丝 ${rel.followerCount} · 关注 ${rel.followingCount}"
                    list.adapter = ArrayAdapter(
                        activity, android.R.layout.simple_list_item_1,
                        posts.map { "${it.content}  ♥${it.likeCount} 💬${it.commentCount}" }
                    )
                }
            }
        }

        ll.addView(name); ll.addView(bio); ll.addView(counts); ll.addView(list)
        activity.setContentView(ll)
        refresh()
    }
}
