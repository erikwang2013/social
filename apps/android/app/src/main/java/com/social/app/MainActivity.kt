package com.social.app

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.social.app.api.ApiClient
import com.social.app.api.LoginScreen
import com.social.app.api.TimelineScreen

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (ApiClient.accessToken.isEmpty()) {
            LoginScreen.show(this) { TimelineScreen.show(this) }
        } else {
            TimelineScreen.show(this)
        }
    }
}
