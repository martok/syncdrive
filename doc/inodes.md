## Inode semantics

### General

- Inode form a tree, connected by Inode->parent
- every Inode is *owned* by a User
- The *root* of a User's tree is a single Inode with parent==NULL
  - only one of those exists for each user
- a thing is *tree-owned* if all its recursive parents are owned by the same User


### Sharing

#### Outgoing share

- When an Inode is shared, one or more InodeShares exist
- InodeShare defines the semantics of accessing an Inode in a specific way:
  - external token name / password or NULL if only internal
  - permissions
  - User that created the share
    - identical to owner of the Inode when new shares are created (only the owner can share a thing)
    - different from owner if an InodeShare was re-shared by User with sufficient permissions
- InodeShare points to an Inode that is then handled as usual

#### Received share

- Internal shares are received as Inodes with type TYPE_INTERNAL_SHARE and link_target = InodeShare->id
- The Dav\Node type of such a node is derived from the target
  - Implied: it is invalid to share an Inode that is already a share (that is re-sharing, handled explicitly), Share targets must be regular Files/Directories
- Most operations act on the "inner" Inode
- Name and moving the Node itself act on the "outer" Inode

#### Traversal

- The original sharing User always has the shared Inode in their own tree, as creating a sub-share from a received share is not allowed
  - parent-traversal works
- A received share can only exist in a tree-owned Directory
  - parent-traversal works