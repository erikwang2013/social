package com.social.app.api

import android.app.Activity
import android.graphics.Typeface
import android.view.View
import android.view.ViewGroup
import android.widget.*
import java.util.concurrent.Executors

object NotificationScreen {
    fun show(activity: Activity) {
        val ll = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
        val readAll = Button(activity).apply { text = "全部已读" }
        val list = ListView(activity)
        val executor = Executors.newSingleThreadExecutor()

        fun refresh() {
            executor.execute {
                val items = ApiClient.notifications()
                activity.runOnUiThread {
                    list.adapter = object : BaseAdapter() {
                        override fun getCount() = items.size
                        override fun getItem(p: Int) = items[p]
                        override fun getItemId(p: Int) = items[p].id
                        override fun getView(p: Int, convert: View?, parent: ViewGroup): View {
                            val tv = convert as? TextView ?: TextView(activity)
                            val n = items[p]
                            val who = n.actor?.nickname ?: "有人"
                            tv.text = when (n.type) {
                                "like" -> "$who 赞了你的动态"
                                "comment" -> "$who 评论了你的动态：${n.content}"
                                "follow" -> "$who 关注了你"
                                else -> n.type
                            }
                            tv.setTypeface(null, if (n.read) Typeface.NORMAL else Typeface.BOLD)
                            tv.setPadding(24, 16, 24, 16)
                            return tv
                        }
                    }
                }
            }
        }

        readAll.setOnClickListener {
            executor.execute {
                ApiClient.markAllRead()
                activity.runOnUiThread { refresh() }
            }
        }
        ll.addView(readAll); ll.addView(list)
        activity.setContentView(ll)
        refresh()
    }
}
