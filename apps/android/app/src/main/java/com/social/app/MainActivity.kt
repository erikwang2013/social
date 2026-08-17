package com.social.app

import android.os.Bundle
import android.widget.Button
import android.widget.LinearLayout
import androidx.appcompat.app.AppCompatActivity
import com.social.app.api.ApiClient
import com.social.app.api.LoginScreen
import com.social.app.api.NotificationScreen
import com.social.app.api.TimelineScreen
import com.social.app.api.UserScreen

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (ApiClient.accessToken.isEmpty()) {
            LoginScreen.show(this) { showHub() }
        } else {
            showHub()
        }
    }

    private fun showHub() {
        val ll = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        val tl = Button(this).apply { text = "时间线" }
        val me = Button(this).apply { text = "我的主页" }
        val nt = Button(this).apply { text = "通知" }
        tl.setOnClickListener { TimelineScreen.show(this) }
        me.setOnClickListener { UserScreen.show(this) }
        nt.setOnClickListener { NotificationScreen.show(this) }
        ll.addView(tl); ll.addView(me); ll.addView(nt)
        setContentView(ll)
    }
}
