package com.social.app.api

import android.app.Activity
import android.widget.*
import java.util.concurrent.Executors

object TimelineScreen {
    fun show(activity: Activity) {
        val ll = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
        val input = EditText(activity).apply { hint = "说点什么…" }
        val send = Button(activity).apply { text = "发布" }
        val list = ListView(activity)
        val executor = Executors.newSingleThreadExecutor()

        fun refresh() {
            executor.execute {
                val posts = ApiClient.timeline()
                activity.runOnUiThread {
                    list.adapter = ArrayAdapter(
                        activity, android.R.layout.simple_list_item_1,
                        posts.map { "${it.content}  ♥${it.likeCount} 💬${it.commentCount}" }
                    )
                }
            }
        }

        send.setOnClickListener {
            executor.execute {
                ApiClient.createPost(input.text.toString())
                activity.runOnUiThread { input.setText(""); refresh() }
            }
        }
        ll.addView(input); ll.addView(send); ll.addView(list)
        activity.setContentView(ll)
        refresh()
    }
}
