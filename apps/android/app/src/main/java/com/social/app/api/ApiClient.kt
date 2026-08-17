package com.social.app.api

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
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

private const val BASE = "http://10.0.2.2:8788" // 模拟器访问宿主机

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

@Serializable
data class UserBriefItem(
    val id: Long,
    val nickname: String = "",
    val avatar: String = "",
    val bio: String = "",
    val gender: Int = 0,
)

@Serializable
data class RelationData(
    @kotlinx.serialization.SerialName("is_following") val isFollowing: Boolean,
    @kotlinx.serialization.SerialName("follower_count") val followerCount: Long,
    @kotlinx.serialization.SerialName("following_count") val followingCount: Long,
)

@Serializable
data class NotificationItem(
    val id: Long,
    val type: String,
    @kotlinx.serialization.SerialName("ref_type") val refType: String = "",
    @kotlinx.serialization.SerialName("ref_id") val refId: Long = 0,
    val content: String = "",
    val read: Boolean = false,
    @kotlinx.serialization.SerialName("created_at") val createdAt: String = "",
    val actor: UserBriefItem? = null,
)

@Serializable
data class MeData(val user: MeUser? = null, val profile: ProfileItem? = null)

@Serializable
data class MeUser(val id: Long)

@Serializable
data class ProfileItem(
    val nickname: String = "",
    val avatar: String = "",
    val bio: String = "",
    val gender: Int = 0,
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

    fun timeline(): List<PostItem> =
        getList("/api/v1/posts").map { json.decodeFromJsonElement(PostItem.serializer(), it) }

    fun createPost(content: String): Boolean {
        val jsonBody = kotlinx.serialization.json.buildJsonObject { put("content", content) }.toString()
        return post("/api/v1/posts", jsonBody)
    }

    fun follow(userId: Long): Boolean = post("/api/v1/users/$userId/follow")

    fun unfollow(userId: Long): Boolean = post("/api/v1/users/$userId/unfollow")

    fun relation(userId: Long): RelationData {
        val root = json.parseToJsonElement(get("/api/v1/users/$userId/relation").body!!.string()).jsonObject
        return json.decodeFromJsonElement(RelationData.serializer(), root["data"]!!)
    }

    fun notifications(page: Int = 1): List<NotificationItem> =
        getList("/api/v1/notifications?page=$page").map { json.decodeFromJsonElement(NotificationItem.serializer(), it) }

    fun unreadCount(): Int {
        val root = json.parseToJsonElement(get("/api/v1/notifications/unread-count").body!!.string()).jsonObject
        return root["data"]!!.jsonObject["unread_count"]!!.jsonPrimitive.int
    }

    fun markAllRead(): Boolean = post("/api/v1/notifications/read-all")

    fun me(): Pair<Long, ProfileItem> {
        val data = json.parseToJsonElement(get("/api/v1/auth/me").body!!.string()).jsonObject["data"]!!.jsonObject
        val user = json.decodeFromJsonElement(MeUser.serializer(), data["user"]!!)
        val profile = json.decodeFromJsonElement(ProfileItem.serializer(), data["profile"]!!)
        return user.id to profile
    }

    private fun get(path: String): okhttp3.Response =
        client.newCall(baseRequest("GET", path).build()).execute()

    private fun getList(path: String): List<JsonElement> {
        val root = json.parseToJsonElement(get(path).body!!.string()).jsonObject
        return root["data"]!!.jsonObject["list"]!!.jsonArray.toList()
    }

    private fun post(path: String, body: String? = null): Boolean {
        val resp = client.newCall(baseRequest("POST", path, body).build()).execute()
        return resp.isSuccessful && json.parseToJsonElement(resp.body!!.string()).jsonObject["code"]!!.jsonPrimitive.int == 0
    }
}
