package com.social.app

import android.os.Bundle
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val tv = TextView(this).apply { text = "checking…"; textSize = 24f }
        setContentView(tv)
        CoroutineScope(Dispatchers.IO).launch {
            val health = APIClient.health()
            runOnUiThread { tv.text = health }
        }
    }
}
