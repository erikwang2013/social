package com.social.app.api

import android.app.Activity
import android.widget.*

object LoginScreen {
    fun show(activity: Activity, onSuccess: () -> Unit) {
        val ll = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
        val email = EditText(activity).apply { hint = "邮箱" }
        val pass = EditText(activity).apply { hint = "密码"; inputType = android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD }
        val nickname = EditText(activity).apply { hint = "昵称(注册)" }
        val btn = Button(activity).apply { text = "登录" }
        val regBtn = Button(activity).apply { text = "注册并登录" }
        val msg = TextView(activity)

        btn.setOnClickListener {
            val r = ApiClient.login(email.text.toString(), pass.text.toString())
            if (r.code == 0) onSuccess() else msg.text = r.message
        }
        regBtn.setOnClickListener {
            val r = ApiClient.register(email.text.toString(), pass.text.toString(), nickname.text.toString())
            if (r.code == 0) onSuccess() else msg.text = r.message
        }
        ll.addView(email); ll.addView(pass); ll.addView(nickname); ll.addView(btn); ll.addView(regBtn); ll.addView(msg)
        activity.setContentView(ll)
    }
}
