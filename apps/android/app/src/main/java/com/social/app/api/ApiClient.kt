package com.social.app.api

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.decodeFromJsonElement
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

private const val BASE = "http://10.0.2.2:8787" // 模拟器访问宿主机

@Serializable
data class LoginResponse(
    val code: Int,
    val message: String = "",
    @kotlinx.serialization.SerialName("lang_key") val langKey: String = "",
    val data: TokenData? = null,
)

@Serializable
data class TokenData(
    @kotlinx.serialization.SerialName("access_token") val accessToken: String,
    @kotlinx.serialization.SerialName("refresh_token") val refreshToken: String,
    @kotlinx.serialization.SerialName("expires_in") val expiresIn: Long,
)

@Serializable
data class PostItem(
    val id: Long,
    val content: String,
    @kotlinx.serialization.SerialName("like_count") val likeCount: Int = 0,
    @kotlinx.serialization.SerialName("comment_count") val commentCount: Int = 0,
    @kotlinx.serialization.SerialName("created_at") val createdAt: String = "",
)

object ApiClient {
    private val json = Json { ignoreUnknownKeys = true }
    private val client = OkHttpClient.Builder().build()

    var accessToken: String = ""

    private fun baseRequest(method: String, path: String, body: String? = null): Request.Builder {
        val rb = Request.Builder()
            .url(BASE + path)
            .method(method, body?.toRequestBody("application/json".toMediaType()))
        if (accessToken.isNotEmpty()) rb.header("Authorization", "Bearer $accessToken")
        return rb
    }

    fun login(email: String, password: String): LoginResponse {
        val jsonBody = kotlinx.serialization.json.buildJsonObject {
            put("email", email); put("password", password)
        }.toString()
        val resp = client.newCall(baseRequest("POST", "/api/v1/auth/login", jsonBody).build()).execute()
        val parsed = json.decodeFromString<LoginResponse>(resp.body!!.string())
        parsed.data?.let { accessToken = it.accessToken }
        return parsed
    }

    fun register(email: String, password: String, nickname: String): LoginResponse {
        val jsonBody = kotlinx.serialization.json.buildJsonObject {
            put("email", email); put("password", password); put("nickname", nickname)
        }.toString()
        val resp = client.newCall(baseRequest("POST", "/api/v1/auth/register", jsonBody).build()).execute()
        val parsed = json.decodeFromString<LoginResponse>(resp.body!!.string())
        parsed.data?.let { accessToken = it.accessToken }
        return parsed
    }

    fun timeline(): List<PostItem> {
        val resp = client.newCall(baseRequest("GET", "/api/v1/posts").build()).execute()
        val text = resp.body!!.string()
        val root = json.parseToJsonElement(text).jsonObject
        return root["data"]!!.jsonObject["list"]!!.jsonArray.map {
            json.decodeFromJsonElement(PostItem.serializer(), it)
        }
    }

    fun createPost(content: String): Boolean {
        val jsonBody = kotlinx.serialization.json.buildJsonObject { put("content", content) }.toString()
        val resp = client.newCall(baseRequest("POST", "/api/v1/posts", jsonBody).build()).execute()
        return resp.isSuccessful && json.parseToJsonElement(resp.body!!.string()).jsonObject["code"]!!.jsonPrimitive.int == 0
    }
}
